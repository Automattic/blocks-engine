<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PageStylesheets;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A navigation button list shared by every page: the compatibility replay
// loses all of its rules to extracted chrome, so no route ships an empty file.
$css = 'body{margin:0}#main{max-width:640px}.buttons-component{display:flex;gap:12px;list-style:none;padding:0}.buttons-component>li>a{display:inline-flex;align-items:center;height:40px;padding:0 16px;background:#333;color:#fff;text-decoration:none}';
$nav = '<ul class="buttons-component"><li><a href="/" class="button">Home</a></li><li><a href="/a/" class="button">A</a></li><li><a href="/b/" class="button">B</a></li></ul>';
$files = array();
foreach (array('index.html' => 'Home', 'a/index.html' => 'A', 'b/index.html' => 'B') as $path => $title) {
    $files[$path] = '<!doctype html><html lang="en"><head><title>' . $title . '</title><style>' . $css . '</style></head><body><div id="main">' . $nav . '<h1>' . $title . '</h1><p>' . $title . ' copy</p></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$empty = array_filter($plan['writes'], static fn(array $write): bool => str_ends_with($write['target_path'], '.css') && '' === trim((string) ($write['payload']['data'] ?? 'x')));
$assert(array() === $empty, 'No generated stylesheet is written empty: ' . implode(', ', array_column($empty, 'target_path')));

// Byte-identical copies, one lent to native routes as the frame fallback,
// fold into one resource that keeps the fallback, unless a resource native
// routes also load sits between them.
$copy = static fn(string $page, bool $fallback = false, string $content = '.nav{gap:1px}'): array => array(
    'source_path' => 'assets/css/c-' . md5($page . $content) . '.css', 'target_path' => 'assets/assets/css/c-' . md5($page . $content) . '.css', 'token' => 'asset-' . md5($page . $content),
    'reconciliation_identity' => md5($page . $content), 'source' => 'wordpress-compat', 'kind' => 'css', 'content' => $content, 'references' => array(),
    'scopes' => array(array('kind' => 'page', 'source_path' => $page)),
) + ($fallback ? array('frame_fallback' => true) : array());
$merge = new ReflectionMethod(PageStylesheets::class, 'mergeIdentical');
$merged = $merge->invoke(null, array($copy('index.html'), $copy('a/index.html', true), $copy('b/index.html')), array());
$assert(1 === count($merged) && !empty($merged[0]['frame_fallback']) && 3 === count($merged[0]['scopes']), 'Identical copies fold into one fallback resource: ' . json_encode($merged));
$global = array('scopes' => array(array('kind' => 'global'))) + $copy('shared', false, '.x{color:red}');
$kept = $merge->invoke(null, array($copy('index.html'), $global, $copy('a/index.html', true)), array());
$assert(3 === count($kept), 'A fallback copy does not pass a resource native routes load.');
echo "Generated stylesheet consolidation passed\n";

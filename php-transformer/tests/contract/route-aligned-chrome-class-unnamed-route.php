<?php
declare(strict_types=1);

/**
 * A component's hashed class can share its rules with a sibling class on
 * most routes and stand alone on another, so the routes name one meaning
 * differently and the class gains a route alias. A route whose own sheets
 * never name that class reads it as nothing: its header must wear the same
 * alias, or the shared header differs on that route alone.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$sheets = array(
    'index' => '.label,.caption{margin:0 auto;white-space:nowrap}.home{color:red}',
    'about' => '.label,.caption{margin:0 auto;white-space:nowrap}.about{color:blue}',
    'cart' => '.label{margin:0 auto;white-space:nowrap}.cart{color:green}',
    'gallery' => '.gallery{color:purple}',
);
$files = array();
$routes = array();
foreach ($sheets as $route => $css) {
    $files[] = array('path' => 'assets/' . $route . '.css', 'kind' => 'css', 'content' => $css);
    $path = 'index' === $route ? 'index.html' : $route . '/index.html';
    $routes[$path] = $route;
    $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="/assets/' . $route . '.css"></head><body><header><nav><a href="/">Home</a><button type="button"><span class="label">Cart</span></button></nav></header><main><h1 class="' . $route . '">' . $route . '</h1></main></body></html>');
}
$classes = array();
foreach ((new RouteStateProjector())->project($files)['files'] as $file) {
    if ('html' !== $file['kind']) continue;
    $document = new DOMDocument();
    @$document->loadHTML((string) $file['content']);
    $classes[$routes[$file['path']]] = (string) (new DOMXPath($document))->evaluate('string(//header//button/span/@class)');
}
if ('label' === $classes['index'] || !str_starts_with($classes['index'], 'route-class-')) throw new RuntimeException('The differently named meaning must be route-aligned: ' . json_encode($classes));
if (1 !== count(array_unique($classes))) throw new RuntimeException('Every route must wear the same header class, including the route whose sheets never name it: ' . json_encode($classes));

fwrite(STDOUT, "route-aligned-chrome-class-unnamed-route: ok\n");

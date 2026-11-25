<?php
declare(strict_types=1);

/**
 * A captured panel's CSS depends on the route that captured it: one route
 * carries every rule of the component's sheet, another only the rules that
 * styled the panel as it opened. The extra rules select states and parts
 * the panel does not render (a disabled button, a path the icon lacks), so
 * the class means the same on both routes. It keeps its name, and the
 * header reads the same on every route.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$live = '.icon svg{display:block}.button .icon svg[fill=currentColor]{color:red}';
$dead = '.button.is-disabled .icon svg[fill=currentColor]{color:gray}.button .icon svg:not([fill=currentColor]) path{stroke:red}';
$sheets = array(
    'index' => $live . $dead . '.home{color:red}',
    'shop' => $live . $dead . '.shop{color:blue}',
    'events' => $live . '.events{color:green}',
);
$files = array();
$routes = array();
foreach ($sheets as $route => $css) {
    $files[] = array('path' => 'assets/' . $route . '.css', 'kind' => 'css', 'content' => $css);
    $path = 'index' === $route ? 'index.html' : $route . '/index.html';
    $routes[$path] = $route;
    $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="/assets/' . $route . '.css"></head><body><header><nav><a href="/">Home</a><button type="button" class="button"><span class="icon"><svg fill="currentColor" viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg></span></button></nav></header><main><h1 class="' . $route . '">' . $route . '</h1></main></body></html>');
}
$classes = array();
foreach ((new RouteStateProjector())->project($files)['files'] as $file) {
    if ('html' !== $file['kind']) continue;
    $document = new DOMDocument();
    @$document->loadHTML((string) $file['content']);
    $classes[$routes[$file['path']]] = (string) (new DOMXPath($document))->evaluate('string(//header//button/span/@class)');
}
if (array('icon') !== array_values(array_unique($classes))) throw new RuntimeException('Rules that select nothing must not make one class mean something else on another route: ' . json_encode($classes));

fwrite(STDOUT, "route-aligned-chrome-class-dead-rules: ok\n");

<?php
declare(strict_types=1);

/**
 * Two routes restate one component's sheet, minified differently: one
 * keeps the quotes around an identifier attribute value and the spaces
 * around a child combinator, the other drops them. Both spell the same
 * selector, so the class means the same on both routes, keeps its name,
 * and the header reads the same on every route.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$sheets = array(
    'index' => '.icon > svg[fill="currentColor"]{color:red}.home{color:red}',
    'shop' => '.icon>svg[fill=currentColor]{color:red}.shop{color:blue}',
    'events' => ".icon >  svg[ fill='currentColor' ]{color:red}.events{color:green}",
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
if (array('icon') !== array_values(array_unique($classes))) throw new RuntimeException('A selector minified differently must not make one class mean something else on another route: ' . json_encode($classes));

fwrite(STDOUT, "route-aligned-chrome-class-selector-spelling: ok\n");

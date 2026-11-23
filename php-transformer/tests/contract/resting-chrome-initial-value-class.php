<?php
declare(strict_types=1);

/**
 * A component's script can add its root class on one route only (it
 * hydrated there), and that route's sheet carries the class's one rule,
 * which restates a property's initial value (`box-shadow:none`). Nothing
 * else gives the element a shadow, so it renders the same with or without
 * the class: the class is dropped and the header reads the same on every
 * route. Where another rule does give the element that property, the class
 * matters and stays.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$run = static function (string $shared): array {
    $routes = array('index.html' => '', 'about/index.html' => '', 'gallery/index.html' => ' _root_x1');
    $files = array();
    foreach ($routes as $path => $extra) {
        $css = $shared . ('' === $extra ? '' : '._root_x1{box-shadow:none!important}._consent_x1{position:absolute}');
        $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><style>' . $css . '</style></head><body><header id="top"><nav><a href="/">Home</a><a href="/about/">About</a></nav><div id="embed" class="embed html-component' . $extra . '"><iframe title="Widget"></iframe></div></header><main><h1>' . $path . '</h1></main></body></html>');
    }
    $classes = array();
    foreach ((new RouteStateProjector())->project($files)['files'] as $file) {
        if ('html' !== $file['kind']) continue;
        $document = new DOMDocument();
        @$document->loadHTML((string) $file['content']);
        $classes[$file['path']] = (string) (new DOMXPath($document))->evaluate('string(//*[@id="embed"]/@class)');
    }
    return $classes;
};

$inert = $run('.embed{display:block}.card:hover{box-shadow:0 0 4px red}.embed:focus{box-shadow:0 0 2px blue}');
if (array('embed html-component') !== array_values(array_unique($inert))) throw new RuntimeException('A class that only restates an initial value nothing else sets must not split the header: ' . json_encode($inert));

$styled = $run('.embed{display:block}.html-component{box-shadow:0 0 4px red}');
if (!str_contains($styled['gallery/index.html'], '_root_x1')) throw new RuntimeException('A class that removes a shadow another rule gives the element must stay: ' . json_encode($styled));

fwrite(STDOUT, "resting-chrome-initial-value-class: ok\n");

<?php
declare(strict_types=1);

/**
 * Site builders emit each page's component CSS separately: one route can
 * repeat a header rule, or state its breakpoint override before and after
 * the base rule. Those sheets resolve the header identically, so route
 * alignment must not give each route its own alias for the same header.
 * An override the base rule genuinely shadows still reads differently.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$base = '.menu{--width:third;--radius:0px}';
$mobile = '@media screen and (max-width: 1023px){.menu{--width:full}}';
$mobileRadius = '@media screen and (max-width: 1023px){.menu{--radius:0px;--width:full}}';
$headerClasses = static function (array $sheets): array {
    $files = array();
    $routes = array();
    foreach ($sheets as $route => $css) {
        $files[] = array('path' => 'assets/' . $route . '.css', 'kind' => 'css', 'content' => $css);
        $path = 'index' === $route ? 'index.html' : $route . '/index.html';
        $routes[$path] = $route;
        $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="/assets/' . $route . '.css"></head><body><header><nav class="menu"><a href="/">Home</a></nav></header><main><h1>' . $route . '</h1></main></body></html>');
    }
    $classes = array();
    foreach ((new RouteStateProjector())->project($files)['files'] as $file) {
        if ('html' !== $file['kind']) continue;
        $document = new DOMDocument();
        @$document->loadHTML((string) $file['content']);
        $classes[$routes[$file['path']]] = (string) (new DOMXPath($document))->evaluate('string(//header/nav/@class)');
    }
    return $classes;
};

$restated = $headerClasses(array(
    'index' => $base . $mobile,
    // The same sheet emitted twice.
    'about' => $base . $mobile . $base . $mobile,
    // The override stated before and after the base rule, restating a value
    // the base rule already gives.
    'contact' => $mobile . $base . $mobileRadius,
));
foreach ($restated as $route => $class) {
    if ('menu' !== $class) throw new RuntimeException("Equivalent restated header rules must not align {$route} under a route alias: {$class}");
}

// Here the base rule follows and replaces the override on every viewport.
$shadowed = $headerClasses(array('index' => $base . $mobile, 'about' => $mobile . $base));
if ($shadowed['index'] === $shadowed['about']) throw new RuntimeException('A shadowed breakpoint override reads differently and must stay route-aligned.');

echo "Route restated chrome rules alignment: passed\n";

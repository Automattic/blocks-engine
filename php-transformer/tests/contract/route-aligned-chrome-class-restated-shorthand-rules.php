<?php
declare(strict_types=1);

/**
 * A builder can emit one route's component sheet twice. When the rules mix a
 * shorthand with its longhands, their meaning is read in source order, and a
 * repeated copy of a rule still wins exactly where its earlier copy would.
 * The header must keep its own class on every route instead of gaining a
 * route alias for the same rules.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

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

$sheet = '.menu{border:0;padding:0}.menu{border-color:red;padding-top:4px}@media (max-width:600px){.menu{border-width:2px}}';
$restated = $headerClasses(array('index' => $sheet, 'about' => $sheet . $sheet));
foreach ($restated as $route => $class) {
    if ('menu' !== $class) throw new RuntimeException("A repeated shorthand sheet must not align {$route} under a route alias: {$class}");
}

// Reordering the shorthand after its longhand still changes what reads.
$reordered = $headerClasses(array('index' => $sheet, 'about' => '.menu{border-color:red;padding-top:4px}.menu{border:0;padding:0}'));
if ($reordered['index'] === $reordered['about']) throw new RuntimeException('A shorthand that resets its longhands reads differently and must stay route-aligned.');

fwrite(STDOUT, "route-aligned-chrome-class-restated-shorthand-rules: ok\n");

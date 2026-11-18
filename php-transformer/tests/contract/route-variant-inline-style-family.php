<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Every route carries the same <style> element; its body differs per route in
// one width rule. The variants are one stylesheet reconciled across routes,
// not one full content-addressed copy per route.
$shared = '';
for ($i = 1; $i <= 12; ++$i) $shared .= '.rule-' . $i . '{padding:' . $i . 'px;margin:' . $i . 'px}';
$page = static fn (string $title, string $width): string => '<!doctype html><html lang="en"><head><title>' . $title . '</title><style>' . $shared . '.' . $width . '{width:' . substr($width, 2) . 'px}</style></head><body><main class="' . $width . '">'
    . implode('', array_map(static fn (int $i): string => '<p class="rule-' . $i . '">' . $title . ' ' . $i . '</p>', range(1, 12))) . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', 'w-10'),
    'b/index.html' => $page('B', 'w-20'),
    'c/index.html' => $page('C', 'w-30'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$sheets = array();
foreach ($plan['writes'] as $write) {
    if (str_contains($write['target_path'], '/inline/') && str_ends_with($write['target_path'], '.css')) $sheets[$write['target_path']] = (string) $write['payload']['data'];
}
$css = implode("\n", $sheets);
foreach (range(1, 12) as $i) {
    $copies = substr_count($css, '.rule-' . $i . '{');
    if (1 !== $copies) throw new RuntimeException(".rule-{$i} is shared by every route and ships once, not {$copies} times:\n{$css}");
}
foreach (array('w-10' => '10px', 'w-20' => '20px', 'w-30' => '30px') as $class => $width) {
    if (1 !== preg_match('/\.' . $class . '(?![\w-])[^{}]*\{width:' . $width . '\}/', $css)) throw new RuntimeException("The route-specific .{$class} rule still ships:\n{$css}");
}
// The differing rules of the non-entry routes stay gated to their route.
foreach (array('w-20', 'w-30') as $class) {
    if (1 !== preg_match('/be-r-[^{}]*\.' . $class . '(?![\w-])|\.' . $class . '(?![\w-])[^,{]*be-r-/', $css)) throw new RuntimeException("The .{$class} variant rule is route-scoped:\n{$css}");
}
$functions = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === $write['target_path']) $functions = (string) $write['payload']['data'];
}
foreach (array_keys($sheets) as $path) {
    if (!str_contains($functions, substr($path, strpos($path, 'assets/inline/')))) throw new RuntimeException("The reconciled inline sheet {$path} is enqueued.");
}
echo 'Route variant inline style family: ' . count($sheets) . ' inline sheet(s), ' . strlen($css) . " bytes.\n";

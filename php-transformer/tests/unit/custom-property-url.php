<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$root = dirname(__DIR__) . '/fixtures/custom-property-url/';
$files = array();
foreach (array('index.html' => 'html', 'definitions/style.css' => 'css', 'consumers/style.css' => 'css', 'definitions/img/x.svg' => 'image') as $path => $kind) {
    $files[] = array('path' => $path, 'kind' => $kind, 'content' => file_get_contents($root . $path));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/themes/neutral'));
$writes = array_column($resolved['writes'], null, 'target_path');
$asset = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with($asset['source_path'], '/x.svg')))[0];
$expected = '/themes/neutral/' . $asset['target_path'];
if (in_array('--json', $argv, true)) {
    echo json_encode(array('writes' => $resolved['writes'], 'expected' => $expected, 'markup' => preg_replace('/<link[^>]+>/', '', file_get_contents($root . 'index.html'))));
    exit(0);
}
$definitions = array();
foreach ($writes as $path => $write) {
    if (!str_ends_with($path, '.css')) continue;
    if (preg_match('/--icon\s*:\s*url\([\'\"]?([^\)\'\"]+)/', $write['payload']['data'], $match)) $definitions[$path] = $match[1];
}
if (array() === $definitions) throw new RuntimeException('Reduced page must retain its custom property.');
foreach ($definitions as $path => $url) {
    echo $path . ': --icon: url(' . $url . ")\n";
    if ($url !== $expected && $url !== 'https://example.test' . $expected) throw new RuntimeException('Custom-property URL must be location-independent and point to materialized x.svg: ' . $expected);
}
if (!isset($writes[$asset['target_path']])) throw new RuntimeException('The SVG must be materialized.');
echo "PASS: custom-property URLs target the materialized SVG regardless of consuming stylesheet.\n";

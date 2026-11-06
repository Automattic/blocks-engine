<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\BootstrapStyles;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Per-device stylesheets share a document gate; only the link's capture device
// style keeps the mobile rule off desktop. Their chrome slices must stay apart.
$head = '';
$files = array();
foreach (array('desktop' => 980, 'mobile' => 320) as $device => $width) {
    $head .= '<link rel="stylesheet" href="' . $device . '.css" media="not all" data-blocks-engine-capture-source-media="all" data-blocks-engine-capture-device-style="' . $device . '">';
    $files[$device . '.css'] = ':where([data-blocks-engine-capture-device-document="desktop"]) header .bar{width:' . $width . 'px;padding:10px}';
}
foreach (array('index', 'one', 'two', 'three') as $route) {
    $files[$route . '.html'] = '<!doctype html><html><head><title>' . $route . '</title>' . $head . '</head><body>'
        . '<div data-blocks-engine-capture-document-scope="" data-blocks-engine-capture-device-document="desktop">'
        . '<header><div class="bar"><a href="index.html">Home</a></div></header><main><h1>' . $route . '</h1></main></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$widths = array();
foreach ($plan['assets'] as $asset) {
    if (preg_match_all('/width:(\d+)px/', str_contains($asset['target_path'] ?? '', '/shared-chrome-') ? $asset['content'] : '', $match)) $widths[$asset['target_path']] = array_unique($match[1]);
}
$bootstrap = BootstrapStyles::expand(array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data']);
preg_match_all("/\\['(assets\\/css\\/shared-chrome-[a-f0-9]+\\.css)','not all',\\[[^\\]]*'data-blocks-engine-capture-device-style'=>'([a-z ]+)'/", $bootstrap, $links, PREG_SET_ORDER);
$devices = array();
foreach ($links as $link) $devices[$link[1]][$link[2]] = true;
$expected = array('desktop' => array('980'), 'mobile' => array('320'));
$actual = array();
foreach ($widths as $path => $values) $actual[implode(' ', array_keys($devices[$path] ?? array()))] = array_values($values);
ksort($actual);
if ($expected !== $actual) throw new RuntimeException('Each device style owns its own chrome slice: ' . json_encode($actual));

echo "shared-chrome-device-style-slices contract passed\n";

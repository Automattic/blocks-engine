<?php
declare(strict_types=1);

/** Generated ~3,000-element compile contract; optional baseline/result paths. */
require (getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2)) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '.menu{display:flex;gap:12px}.menu a{color:#234;padding:4px}.tile{padding:8px}.tile p{color:#456}';
for ($i = 0; $i < 120; ++$i) $css .= '.tile.kind-' . $i . ' p{margin:2px}';
$html = '<!doctype html><html><head><title>Compile benchmark</title><link rel="stylesheet" href="style.css"></head><body>';
foreach (array('desktop', 'mobile') as $variant) {
    $html .= '<div class="site-document-variant-' . $variant . '">';
    for ($i = 0; $i < 8; ++$i) {
        $id = $variant . '-menu-' . $i;
        $html .= '<header><button aria-controls="' . $id . '" aria-expanded="false" aria-label="Menu"><span></span><span></span><span></span></button><nav id="' . $id . '"><ul class="menu">';
        for ($j = 0; $j < 4; ++$j) $html .= '<li><a href="/page-' . $i . '-' . $j . '/">Page ' . $j . '</a></li>';
        $html .= '</ul></nav></header>';
    }
    $html .= '<main>';
    for ($i = 0; $i < 450; ++$i) $html .= '<section class="tile kind-' . ($i % 120) . '"><h2>Heading ' . $i . '</h2><p>Ordinary page content.</p></section>';
    $html .= '</main></div>';
}
$html .= '</body></html>';
$profiler = class_exists(ExcimerProfiler::class) ? new ExcimerProfiler() : null;
if (null !== $profiler) {
    $profiler->setEventType(EXCIMER_REAL);
    $profiler->setPeriod(0.01);
    $profiler->start();
}
$started = hrtime(true);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'style.css' => $css)))->toArray();
$seconds = (hrtime(true) - $started) / 1e9;
if (null !== $profiler) {
    $profiler->stop();
    if (getenv('COMPILE_PROFILE')) file_put_contents(getenv('COMPILE_PROFILE'), $profiler->getLog()->formatCollapsed());
}
$output = array('serialized_blocks' => $result['serialized_blocks'], 'site_plan' => $result['site_plan'] ?? $result['source_reports']['wordpress_site_plan'] ?? null);
$hash = hash('sha256', serialize($output));
if ('--contract' === ($argv[1] ?? '')) {
    if ('5def8e1ea83add651099183eb0e3c180b23a1a8fcbb16368adcff2285f86371d' !== $hash) throw new RuntimeException('Generated fixture differs from pre-optimization blocks/site plan');
} elseif (isset($argv[1])) {
    $encoded = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (is_file($argv[1]) && file_get_contents($argv[1]) !== $encoded) throw new RuntimeException('Compile output differs from baseline');
    if (!is_file($argv[1])) file_put_contents($argv[1], $encoded);
}
fwrite(STDOUT, json_encode(array('seconds' => $seconds, 'hash' => $hash), JSON_PRETTY_PRINT) . "\n");

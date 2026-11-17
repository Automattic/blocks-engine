<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Folding adjacent support sheets saves requests on small sites, but a folded
// resource loads on every route. A document whose support CSS is large must
// keep it on its own route instead of shipping it to every other page (#6145):
// the other routes' stylesheet weight must not carry it.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array();
foreach (array('index.html', 'about.html', 'contact.html') as $i => $path) {
    $boxes = '<div class="box" style="width:' . (302 + $i) . 'px;height:200px"><p>Copy ' . $i . '</p></div>';
    // The front page carries many distinct inline boxes: kilobytes of support.
    if (0 === $i) for ($n = 0; $n < 60; ++$n) $boxes .= '<div class="box" style="width:' . (400 + $n) . 'px;height:' . (100 + $n) . 'px;margin-left:' . $n . 'px"><p>Item ' . $n . '</p></div>';
    $files[$path] = '<!doctype html><html><head><style>.box{width:301px;padding:13px}</style></head><body><main>' . $boxes . '</main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$support = array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'engine-support' === ($asset['source'] ?? '')), null, 'target_path');
$bytes = static function (string $source) use ($plan, $support): array {
    $loaded = array();
    foreach (ThemeBootstrap::documentStylesheets($plan, $source) as $sheet) if (isset($support[$sheet['target_path']])) $loaded[$sheet['target_path']] = $support[$sheet['target_path']]['content'];
    return $loaded;
};
$front = implode("\n", $bytes('index.html'));
$assert(str_contains($front, 'width:459px'), 'The front page loads its own support.');
foreach (array('about.html', 'contact.html') as $i => $source) {
    $css = implode("\n", $bytes($source));
    $assert(str_contains($css, 'width:' . (303 + $i) . 'px'), "{$source} loads its own support.");
    $assert(!str_contains($css, 'width:459px'), "{$source} does not load the front page's support weight: " . strlen($css) . ' bytes');
}
echo "Folded support owner weight passed\n";

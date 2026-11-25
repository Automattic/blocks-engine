<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$counter = new ReflectionProperty(UnreachableSelectorPruner::class, 'evidenceIndexBuildCount');
$artifact = array('entrypoints' => array('page-0.html'), 'files' => array(
    array('path' => 'site.css', 'content' => ':root{--shared:blue;--local:red;--unused:black}.card{color:var(--shared)}.absent{color:var(--unused)}'),
));
// A neutral shared utility sheet makes repeated reachability measurable.
for ($i = 0; $i < 1500; ++$i) {
    $artifact['files'][0]['content'] .= 'section.missing-' . $i . '::before{color:var(--unused)}';
}
for ($i = 0; $i < 4; ++$i) {
    $artifact['files'][] = array('path' => "page-$i.html", 'content' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><style>.page-' . $i . '{background:var(--local)}</style></head><body><main class="card page-' . $i . '"><h1>Page ' . $i . '</h1></main></body></html>');
}
$compiler = new ArtifactCompiler();
$before = $counter->getValue();
$shared = $compiler->prepareShared($artifact);
$assert(1 === $counter->getValue() - $before, 'Shared preparation must build exactly one site evidence index.');
$shared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$plans = $compiler->preparePages($artifact, $shared);
$compile = static function (array $shared, array $plans): array {
    $receipts = array();
    foreach ($plans as $plan) $receipts += (new ArtifactCompiler())->compilePreparedPages($shared, array($plan));
    return $receipts;
};
// Warm unrelated transformer caches before measuring either path.
$compile($shared, array(reset($plans)));
$before = $counter->getValue();
$start = hrtime(true);
$cached = $compile($shared, $plans);
$afterMs = (hrtime(true) - $start) / 1e6 / count($plans);
$assert($before === $counter->getValue(), 'Fresh page workers must restore the site index, not reconstruct it.');

// Old v1 plans remain supported; compare their canonical receipts as well as work.
$legacy = $shared;
unset($legacy['analysis']['site_selector_pruner']);
$invoke = static fn (string $method, array $value) => (new ReflectionMethod(ArtifactCompiler::class, $method))->invoke($compiler, $value);
$legacy['digest'] = $invoke('planDigest', $invoke('sharedPlanDigestInput', $legacy));
foreach ($plans as &$plan) {
    $plan['shared_digest'] = $legacy['digest'];
    $plan['digest'] = $invoke('planDigest', $invoke('pagePlanDigestInput', $plan));
}
unset($plan);
$start = hrtime(true);
$uncached = $compile($legacy, $plans);
$beforeMs = (hrtime(true) - $start) / 1e6 / count($plans);
$assert($counter->getValue() - $before >= count($plans), 'Legacy path exercises the original per-page construction.');
foreach ($cached as $id => $receipt) {
    $expected = $uncached[$id];
    foreach (array('digest', 'shared_digest', 'work') as $key) unset($receipt[$key], $expected[$key]);
    $assert($receipt === $expected, 'Portable index must not change compiled bytes: ' . $id);
}
printf("Site selector pruner: fresh-worker per-page compile before %.2f ms / after %.2f ms\n", $beforeMs, $afterMs);

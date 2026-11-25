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
$cached = $compile($shared, $plans);
$assert($before === $counter->getValue(), 'Fresh page workers must restore the site index, not reconstruct it.');

// The same path must produce identical receipts for in-process and transported plans.
$local = $compiler->prepareShared($artifact);
$uncached = $compile($local, $compiler->preparePages($artifact, $local));
foreach ($cached as $id => $receipt) {
    $expected = $uncached[$id];
    foreach (array('digest', 'shared_digest', 'work') as $key) unset($receipt[$key], $expected[$key]);
    $assert($receipt === $expected, 'Portable index must not change compiled bytes: ' . $id);
}
// A large neutral vocabulary catches accidental retention of expanded index arrays.
$texts = array('<html><body class="card">' . implode(' ', array_map(static fn (int $i): string => 'neutral-token-' . $i, range(0, 30000))) . '</body></html>');
$pruner = new UnreachableSelectorPruner($texts, true, array('.card{color:red}.absent{color:blue}'));
$start = memory_get_usage();
$state = $pruner->exportState();
$assert(memory_get_usage() - $start < 200000, 'Portable index must not retain an expanded duplicate of the live token set.');
$state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$before = $counter->getValue();
$restored = UnreachableSelectorPruner::fromState($texts, $state);
$assert($before === $counter->getValue(), 'Restoring the portable index must not scan site evidence.');
foreach (array('.card', '.absent', 'body.neutral-token-30000', 'missing-element', '[class]') as $selector) {
    $assert($pruner->reachable($selector) === $restored->reachable($selector), 'Restored reachability differs: ' . $selector);
}
$assert($pruner->prune('.card{color:red}.absent{color:blue}') === $restored->prune('.card{color:red}.absent{color:blue}'), 'Restored property and selector indexes must preserve CSS bytes.');
echo "Site selector pruner: compact export and fresh-worker reuse passed\n";

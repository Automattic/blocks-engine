<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$get = static fn (ArtifactCompiler $compiler, string $name) => (new ReflectionProperty($compiler, $name))->getValue($compiler);
$reader = new class implements PayloadReader {
    public array $payloads = array();
    public function read(array $reference): string { return $this->payloads[$reference['id']]; }
};
$artifact = array('entrypoints' => array('page-0.html'), 'files' => array());
$add = static function (string $path, string $bytes) use (&$artifact, $reader): void {
    $reader->payloads[$path] = $bytes;
    $artifact['files'][] = array('path' => $path, 'payload_reference' => array(
        'schema' => 'blocks-engine/payload-reference/v1',
        'id' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ));
};
$add('site.css', 'main{color:#123}');
$add('parts/header.html', '<header><p>Shared header</p></header>');
for ($i = 0; $i < 12; ++$i) {
    $add("page-$i.html", '<!--#include virtual="/parts/header.html" --><link rel="stylesheet" href="site.css"><style>.page-' . $i . '{padding:1rem}</style><main class="page-' . $i . '"><h1>Page ' . $i . '</h1><img src="a.png"></main>');
}
// Duplicate raw content must be inspected only once, even under different paths.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
$add('a.png', $png);
$add('b.png', $png);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact, $reader);
$assert(1 === $get($compiler, 'glyphColorInspectionCount'), 'Shared preparation inspects each raw image hash once.');
$assert(1 === count($shared['shared_reduction']['glyph_colors'] ?? array()), 'Portable shared reduction contains one glyph result per hash.');
$before = $get($compiler, 'stagePartitionCount');
$start = hrtime(true);
$plans = $compiler->preparePages($artifact, $shared, $reader);
$batchMs = (hrtime(true) - $start) / 1e6;
$assert(1 === $get($compiler, 'stagePartitionCount') - $before, 'Reference batch normalizes/projects the whole artifact once, not once per page.');
$start = hrtime(true);
foreach ($plans as $id => $plan) {
    $single = $compiler->preparePage($artifact, $shared, $id, $reader);
    $assert($plan === $single, 'Batch plan is byte-identical to single-page preparation: ' . $id);
}
$singleMs = (hrtime(true) - $start) / 1e6;

// A new compiler models a worker that receives only serialized plans.
$shared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$worker = new ArtifactCompiler();
$start = hrtime(true);
$receipts = array();
foreach ($plans as $id => $plan) {
    $receipts += $worker->compilePreparedPages($shared, array($plan), $reader);
}
$cachedMs = (hrtime(true) - $start) / 1e6;
$assert(0 === $get($worker, 'glyphColorInspectionCount'), 'Separate worker reuses portable colours across multiple compilePreparedPages calls.');

// Simulate a pre-extension v1 plan. It remains valid, and its worker must carry
// the in-memory memo across calls. Rebind only transport identity for comparison.
$legacy = $shared;
unset($legacy['shared_reduction']['glyph_colors']);
$invoke = static fn (string $method, array $value) => (new ReflectionMethod(ArtifactCompiler::class, $method))->invoke($compiler, $value);
$legacy['shared_reduction_digest'] = $invoke('planDigest', $legacy['shared_reduction']);
$legacy['digest'] = $invoke('planDigest', $invoke('sharedPlanDigestInput', $legacy));
$invalid = $shared;
$invalid['shared_reduction']['glyph_colors'] = array('not-a-hash' => array('color' => '', 'undecodable' => false));
$invalid['shared_reduction_digest'] = $invoke('planDigest', $invalid['shared_reduction']);
$invalid['digest'] = $invoke('planDigest', $invoke('sharedPlanDigestInput', $invalid));
try {
    (new ArtifactCompiler())->compilePreparedPages($invalid, array(), $reader);
    throw new RuntimeException('Malformed digest-bound glyph cache was accepted.');
} catch (InvalidArgumentException) {
    // The optional v1 extension is validated, not merely digest checked.
}
$legacyWorker = new ArtifactCompiler();
$start = hrtime(true);
foreach ($plans as $id => $plan) {
    $plan['shared_digest'] = $legacy['digest'];
    $plan['digest'] = $invoke('planDigest', $invoke('pagePlanDigestInput', $plan));
    $receipt = $legacyWorker->compilePreparedPages($legacy, array($plan), $reader)[$id];
    $expected = $receipts[$id];
    foreach (array('digest', 'shared_digest', 'shared_reduction_digest') as $key) {
        unset($receipt[$key], $expected[$key]);
    }
    unset($receipt['work']['compile_duration_ms'], $expected['work']['compile_duration_ms']);
    $assert($receipt === $expected, 'Glyph cache does not change receipt content: ' . $id);
}
$legacyMs = (hrtime(true) - $start) / 1e6;
$assert(1 === $get($legacyWorker, 'glyphColorInspectionCount'), 'Legacy-plan worker memo survives separate batch calls.');
// Emulate the old one-fresh-compiler-per-call behaviour for timing only.
$start = hrtime(true);
$legacyReceipts = array();
foreach ($plans as $id => $plan) {
    $plan['shared_digest'] = $legacy['digest'];
    $plan['digest'] = $invoke('planDigest', $invoke('pagePlanDigestInput', $plan));
    $legacyReceipts += (new ArtifactCompiler())->compilePreparedPages($legacy, array($plan), $reader);
}
$coldMs = (hrtime(true) - $start) / 1e6;
$start = hrtime(true);
$beforeOutput = (new ArtifactCompiler())->compose($legacy, $legacyReceipts, $reader)->toArray();
$composeBeforeMs = (hrtime(true) - $start) / 1e6;
$start = hrtime(true);
$afterOutput = (new ArtifactCompiler())->compose($shared, $receipts, $reader)->toArray();
$composeAfterMs = (hrtime(true) - $start) / 1e6;
$assert($beforeOutput['source_reports']['wordpress_site_plan'] === $afterOutput['source_reports']['wordpress_site_plan'], 'Composed site plans are byte-identical.');
printf("Fresh-per-call compile %.2f ms; compose before %.2f ms / after %.2f ms\n", $coldMs, $composeBeforeMs, $composeAfterMs);
printf("Shared work regression passed: prepare per-page %.2f ms / batch %.2f ms; compile legacy %.2f ms / portable cache %.2f ms\n", $singleMs, $batchMs, $legacyMs, $cachedMs);

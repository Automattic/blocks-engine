<?php
declare(strict_types=1);

/**
 * Per-page compile cost by transform step.
 *
 * Compiles every page of an artifact through the staged path and reports
 * compile ms per page plus the per-step totals aggregated from each page
 * receipt's step_timings. A sha256 over every compiled document's serialized
 * blocks (and one over the full compiled documents) lets a baseline and a
 * candidate tree prove byte-identical output.
 *
 * Usage: php compile-page-steps.php <artifact.json | fixture-dir> [pages]
 *   An artifact JSON is the format compose-page-scaling.php writes with
 *   COMPOSE_SCALING_ARTIFACT_OUT. A fixture dir is read as-is (every file,
 *   index.html as the entrypoint). pages limits compilation to the first n.
 * COMPILE_STEPS_TOP=<n> prints the n slowest steps (default 12).
 * COMPILE_STEPS_SAMPLE=<file> (requires ext-excimer) writes collapsed CPU (wall on platforms without a CPU timer)
 * stacks sampled every 10ms of compilation, for flame graphs or grep.
 */

$transformerRoot = getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2);
require $transformerRoot . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$input = $argv[1] ?? '';
if ('' === $input) {
    fwrite(STDERR, "Usage: php compile-page-steps.php <artifact.json | fixture-dir> [pages]\n");
    exit(1);
}
if (is_dir($input)) {
    $root = rtrim(realpath($input) ?: $input, '/') . '/';
    $files = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && 'fixture.json' !== $file->getFilename()) {
            $files[] = array('path' => substr($file->getPathname(), strlen($root)), 'content' => (string) file_get_contents($file->getPathname()));
        }
    }
    usort($files, static fn(array $l, array $r): int => strcmp($l['path'], $r['path']));
    $artifact = array('entrypoints' => array('index.html'), 'compiler_limits' => array('max_files' => count($files) + 10, 'max_total_bytes' => 512 * 1024 * 1024), 'files' => $files);
} else {
    $artifact = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
}

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
if (isset($argv[2])) {
    $pages = array_slice($pages, 0, (int) $argv[2], true);
}

$profiler = null;
if (getenv('COMPILE_STEPS_SAMPLE') && class_exists(ExcimerProfiler::class)) {
    $profiler = new ExcimerProfiler();
    $profiler->setEventType(defined('EXCIMER_CPU') ? EXCIMER_CPU : EXCIMER_REAL);
    $profiler->setPeriod(0.01);
    $profiler->setMaxDepth(250);
    $profiler->start();
}
$started = hrtime(true);
$cpuStarted = getrusage();
$receipts = $compiler->compilePreparedPages($shared, array_values($pages));
$ms = (hrtime(true) - $started) / 1e6;
$cpuEnded = getrusage();
$cpuMs = ($cpuEnded['ru_utime.tv_sec'] - $cpuStarted['ru_utime.tv_sec']) * 1e3 + ($cpuEnded['ru_utime.tv_usec'] - $cpuStarted['ru_utime.tv_usec']) / 1e3;
if (null !== $profiler) {
    $profiler->stop();
    file_put_contents((string) getenv('COMPILE_STEPS_SAMPLE'), $profiler->getLog()->formatCollapsed());
}

$steps = array();
$serialized = hash_init('sha256');
$documents = hash_init('sha256');
foreach ($receipts as $receipt) {
    foreach ($receipt['work']['step_timings'] ?? array() as $step) {
        $steps[$step['step']] = ($steps[$step['step']] ?? 0.0) + $step['ms'];
    }
    foreach ($receipt['compiled_documents'] ?? array() as $path => $document) {
        hash_update($serialized, $path . "\0" . ($document['serialized_blocks'] ?? '') . "\0");
        hash_update($documents, $path . "\0" . json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\0");
    }
}
arsort($steps);
$count = max(1, count($receipts));
fwrite(STDOUT, json_encode(array(
    'pages' => count($receipts),
    'compile_ms' => round($ms, 1),
    'ms_per_page' => round($ms / $count, 1),
    'cpu_ms_per_page' => round($cpuMs / $count, 1),
    'serialized_sha256' => hash_final($serialized),
    'documents_sha256' => hash_final($documents),
    'steps_ms_per_page' => array_map(
        static fn(float $total): float => round($total / $count, 1),
        array_slice($steps, 0, (int) (getenv('COMPILE_STEPS_TOP') ?: 12), true)
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

<?php
declare(strict_types=1);

/**
 * Independent substage profile of staged composition on a real corpus.
 *
 * Reports wall time and memory deltas for each owning phase boundary:
 * prepare/compile receipt production, per-receipt compose validation,
 * terminal receipt reduction, finalizeArtifact, plan projection,
 * compaction, and serialization. The compose progress callback supplies
 * the stage boundaries; no parallel compiler is constructed.
 *
 * Usage: php staged-compose-profile.php <corpus-dir-or-artifact.json>
 *
 * Directory input measures static files only, not SSI capture-fact parity.
 * JSON input preserves the complete caller artifact. Optional environment:
 * COMPOSE_RECEIPTS_OUT / COMPOSE_RECEIPTS_IN: local trusted receipt cache,
 * produced only through prepareShared/preparePages/compilePreparedPages.
 * COMPOSE_SOURCE_ROOT: archived transformer root (same installed dependencies).
 * COMPOSE_REVISION: immutable source revision label; source tree is also hashed.
 * COMPOSE_PLAN_OUT / COMPOSE_COMPACT_OUT: private full-content equality evidence.
 * COMPOSE_SAMPLE=1: at most 300 one-second stack samples, without arguments.
 */

$transformerRoot = getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2);
$loader = require $transformerRoot . '/vendor/autoload.php';
if (getenv('COMPOSE_SOURCE_ROOT')) {
    $loader->setPsr4('Automattic\\BlocksEngine\\PhpTransformer\\', getenv('COMPOSE_SOURCE_ROOT') . '/src');
}

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;

/** @return array<string,mixed> */
function corpusArtifact(string $source): array
{
    if ( is_file($source) ) {
        $json = file_get_contents($source);
        $artifact = false === $json ? null : json_decode($json, true);
        if ( ! is_array($artifact) || ! is_array($artifact['files'] ?? null) ) {
            throw new InvalidArgumentException(sprintf('Corpus artifact is invalid: %s', $source));
        }
        return $artifact;
    }
    if ( ! is_dir($source) ) {
        throw new InvalidArgumentException(sprintf('Corpus directory does not exist: %s', $source));
    }
    $files = array();
    $root = rtrim(realpath($source) ?: $source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ( $iterator as $file ) {
        if ( ! $file->isFile() ) {
            continue;
        }
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)));
        $content = file_get_contents($file->getPathname());
        if ( false === $content ) {
            throw new RuntimeException(sprintf('Could not read corpus file: %s', $path));
        }
        if ( in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('html', 'htm', 'css', 'svg', 'xml', 'txt'), true) ) {
            $files[] = array('path' => $path, 'content' => $content);
        } else {
            $files[] = array('path' => $path, 'content_base64' => base64_encode($content));
        }
    }
    usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    return array('entrypoints' => array('index.html'), 'compiler_limits' => array('max_files' => count($files), 'max_total_bytes' => 100 * 1024 * 1024), 'files' => $files);
}

/** Timed step: [label => [ms, mem_bytes]] using wall time and usage deltas. */
final class PhaseClock
{
    public array $phases = array();
    public int $peakMemoryBytes = 0;
    private float $startedAt;
    private int $startedMem;

    public function begin(string $phase): void
    {
        $this->recordPeak();
        $this->startedAt = hrtime(true);
        $this->startedMem = memory_get_usage(true);
    }

    public function end(string $phase): void
    {
        $ms = (hrtime(true) - $this->startedAt) / 1000000;
        $delta = memory_get_usage(true) - $this->startedMem;
        if ( ! isset($this->phases[$phase]) ) {
            $this->phases[$phase] = array('ms' => 0.0, 'mem_bytes' => 0);
        }
        $this->phases[$phase]['ms'] += $ms;
        $this->phases[$phase]['mem_bytes'] += $delta;
        $this->phases[$phase]['peak_memory_bytes'] = $this->recordPeak();
    }

    public function recordPeak(): int
    {
        $peak = memory_get_peak_usage(true);
        $this->peakMemoryBytes = max($this->peakMemoryBytes, $peak);
        memory_reset_peak_usage();
        return $peak;
    }
}

$corpus = $argv[1] ?? null;
if ( null === $corpus ) {
    fwrite(STDERR, "Usage: php staged-compose-profile.php <corpus-dir-or-artifact.json>\n");
    exit(1);
}
$clock = new PhaseClock();
$compiler = new ArtifactCompiler();
$samples = array();
$sampleCount = 0;
if (getenv('COMPOSE_SAMPLE') === '1' && function_exists('pcntl_alarm')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use (&$samples, &$sampleCount): void {
        $stack = array();
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class'])) $stack[] = $frame['class'] . '::' . $frame['function'];
        }
        $key = implode(' <- ', $stack);
        $samples[$key] = ($samples[$key] ?? 0) + 1;
        if (++$sampleCount < 300) pcntl_alarm(1);
    });
    pcntl_alarm(1);
}

if (getenv('COMPOSE_RECEIPTS_IN')) {
    $cache = file_get_contents(getenv('COMPOSE_RECEIPTS_IN'));
    if (false === $cache) throw new RuntimeException('Could not read receipt cache.');
    $cached = unserialize($cache, array('allowed_classes' => false));
    if (!is_array($cached) || count($cached) !== 2 || !is_array($cached[0]) || !is_array($cached[1])) throw new RuntimeException('Invalid receipt cache.');
    [$sharedPlan, $receipts] = $cached;
    unset($cache, $cached);
    foreach (array('prepare_shared', 'prepare_pages', 'compile_prepared_pages') as $phase) $clock->phases[$phase] = array('ms' => 0.0, 'mem_bytes' => 0, 'peak_memory_bytes' => 0);
} else {
    $artifact = corpusArtifact($corpus);
    $clock->begin('prepare_shared');
    $sharedPlan = $compiler->prepareShared($artifact);
    $clock->end('prepare_shared');

    $clock->begin('prepare_pages');
    $pagePlans = $compiler->preparePages($artifact, $sharedPlan);
    $clock->end('prepare_pages');
    unset($artifact);

    $clock->begin('compile_prepared_pages');
    $receipts = $compiler->compilePreparedPages($sharedPlan, $pagePlans);
    $clock->end('compile_prepared_pages');
    unset($pagePlans);
}
if (getenv('COMPOSE_RECEIPTS_OUT')) {
    file_put_contents(getenv('COMPOSE_RECEIPTS_OUT'), serialize(array($sharedPlan, $receipts)));
}

// The existing compose progress callback reports each owning boundary.
$composeStageStartedAt = hrtime(true);
$composeStageStartedMem = memory_get_usage(true);
$composeStages = array();
$composeStageMem = array();
$composeStagePeak = array();
$composeProgress = static function (string $stage, int $completed, int $total) use ($clock, &$composeStages, &$composeStageMem, &$composeStagePeak, &$composeStageStartedAt, &$composeStageStartedMem): void {
    $key = $stage;
    if ( ! isset($composeStages[$key]) ) {
        $composeStages[$key] = 0.0;
        $composeStageMem[$key] = 0;
    }
    $composeStages[$key] += (hrtime(true) - $composeStageStartedAt) / 1000000;
    $composeStageMem[$key] += memory_get_usage(true) - $composeStageStartedMem;
    $composeStagePeak[$key] = max($composeStagePeak[$key] ?? 0, $clock->recordPeak());
    $composeStageStartedAt = hrtime(true);
    $composeStageStartedMem = memory_get_usage(true);
};

$clock->begin('compose');
$result = $compiler->compose($sharedPlan, $receipts, null, $composeProgress);
$clock->end('compose');
$composeTotalMs = $clock->phases['compose']['ms'];

$clock->begin('plan_projection');
$view = $result->toWordPressSitePlanView();
$clock->end('plan_projection');

$clock->begin('view_compaction');
$compact = ( new WordPressSitePlanView() )->compact($view);
$clock->end('view_compaction');

$clock->begin('view_serialization');
$encoded = json_encode($compact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ( false === $encoded ) {
    throw new RuntimeException('Compact WordPress site plan view is not serializable.');
}
$clock->end('view_serialization');

// Independent canonical plan identity for baseline/candidate equality checks.
// The canonical plan itself was produced inside finalizeArtifact; re-deriving
// it from the envelope measures plan projection in isolation.
$clock->begin('plan_projection_replay');
$replayedPlan = ( new WordPressSitePlan() )->fromResult($result);
$clock->end('plan_projection_replay');

$canonicalPlan = $result->sourceReports['wordpress_site_plan'] ?? array();
$canonicalEncoded = json_encode($canonicalPlan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$canonicalPlanSha = hash('sha256', $canonicalEncoded);
$canonicalIdentity = WordPressSitePlan::canonicalHash($canonicalPlan);
if (getenv('COMPOSE_PLAN_OUT')) file_put_contents(getenv('COMPOSE_PLAN_OUT'), $canonicalEncoded);
if (getenv('COMPOSE_COMPACT_OUT')) file_put_contents(getenv('COMPOSE_COMPACT_OUT'), $encoded);
$replayMatches = $canonicalPlan === $replayedPlan;
// Terminal process counters are attached to the envelope after the original
// plan was built. Report actual replay differences rather than hiding them.
$replayDifferentKeys = array();
foreach ($canonicalPlan as $key => $value) if ($value !== ($replayedPlan[$key] ?? null)) $replayDifferentKeys[] = $key;
unset($replayedPlan, $canonicalPlan, $canonicalEncoded);

$serializedBytes = strlen($encoded);
$compactSha = hash('sha256', $encoded);
unset($encoded, $compact, $view);

$receiptTotalBytes = array_sum(array_map(
    static fn(array $receipt): int => (int) strlen(json_encode($receipt['artifact']['files'] ?? array(), JSON_UNESCAPED_SLASHES)),
    $receipts
));

$sourceRoot = getenv('COMPOSE_SOURCE_ROOT') ?: $transformerRoot;
$sourceHashes = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) $sourceHashes[substr($file->getPathname(), strlen($sourceRoot) + 1)] = hash_file('sha256', $file->getPathname());
}
ksort($sourceHashes, SORT_STRING);
$clock->recordPeak();

fwrite(STDOUT, json_encode(array(
    'compiler' => array('revision' => getenv('COMPOSE_REVISION') ?: null, 'source_tree_sha256' => hash('sha256', json_encode($sourceHashes, JSON_UNESCAPED_SLASHES)), 'php' => PHP_VERSION),
    'fixture' => array(
        'retained_artifact_file_rows' => count($sharedPlan['artifact']['files'] ?? array()) + array_sum(array_map(static fn(array $r): int => count($r['artifact']['files'] ?? array()), $receipts)),
        'pages' => count($receipts),
        'receipt_files_bytes' => $receiptTotalBytes,
        'receipt_cache_sha256' => getenv('COMPOSE_RECEIPTS_IN') ? hash_file('sha256', getenv('COMPOSE_RECEIPTS_IN')) : null,
        'input_mode' => getenv('COMPOSE_RECEIPTS_IN') ? 'receipt_replay' : (is_file($corpus) ? 'complete_json_artifact' : 'static_directory_only'),
    ),
    'stages_ms' => array(
        'prepare_shared' => $clock->phases['prepare_shared']['ms'],
        'prepare_pages' => $clock->phases['prepare_pages']['ms'],
        'compile_prepared_pages' => $clock->phases['compile_prepared_pages']['ms'],
        'compose' => $composeTotalMs,
        'plan_projection' => $clock->phases['plan_projection']['ms'],
        'view_compaction' => $clock->phases['view_compaction']['ms'],
        'view_serialization' => $clock->phases['view_serialization']['ms'],
        'plan_projection_replay' => $clock->phases['plan_projection_replay']['ms'],
    ),
    'compose_stages_ms' => $composeStages,
    'compose_stages_mem_bytes' => $composeStageMem,
    'compose_stages_peak_memory_bytes' => $composeStagePeak,
    'phase_peak_memory_bytes' => array_map(static fn(array $phase): int => $phase['peak_memory_bytes'], $clock->phases),
    'mem_bytes' => array(
        'prepare_shared' => $clock->phases['prepare_shared']['mem_bytes'],
        'prepare_pages' => $clock->phases['prepare_pages']['mem_bytes'],
        'compile_prepared_pages' => $clock->phases['compile_prepared_pages']['mem_bytes'],
        'compose' => $clock->phases['compose']['mem_bytes'],
        'plan_projection' => $clock->phases['plan_projection']['mem_bytes'],
        'view_compaction' => $clock->phases['view_compaction']['mem_bytes'],
        'view_serialization' => $clock->phases['view_serialization']['mem_bytes'],
    ),
    'output' => array(
        'status' => $result->status,
        'canonical_plan_sha256' => $canonicalPlanSha,
        'canonical_plan_identity' => $canonicalIdentity,
        'plan_projection_replay_matches' => $replayMatches,
        'plan_projection_replay_different_keys' => $replayDifferentKeys,
        'compact_view_bytes' => $serializedBytes,
        'compact_view_sha256' => $compactSha,
    ),
    'peak_memory_bytes' => $clock->peakMemoryBytes,
    'samples' => $samples,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

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
 */

$transformerRoot = getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2);
require $transformerRoot . '/vendor/autoload.php';

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
    private float $startedAt;
    private int $startedMem;

    public function begin(string $phase): void
    {
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
    }
}

$corpus = $argv[1] ?? null;
if ( null === $corpus ) {
    fwrite(STDERR, "Usage: php staged-compose-profile.php <corpus-dir-or-artifact.json>\n");
    exit(1);
}
$artifact = corpusArtifact($corpus);
$clock = new PhaseClock();
$compiler = new ArtifactCompiler();

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

// The existing compose progress callback reports each owning boundary.
$composeStageStartedAt = hrtime(true);
$composeStageStartedMem = memory_get_usage(true);
$composeStages = array();
$composeStageMem = array();
$composeProgress = static function (string $stage, int $completed, int $total) use (&$composeStages, &$composeStageMem, &$composeStageStartedAt, &$composeStageStartedMem): void {
    $key = $stage;
    if ( ! isset($composeStages[$key]) ) {
        $composeStages[$key] = 0.0;
        $composeStageMem[$key] = 0;
    }
    $composeStages[$key] += (hrtime(true) - $composeStageStartedAt) / 1000000;
    $composeStageMem[$key] += memory_get_usage(true) - $composeStageStartedMem;
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
$canonicalPlanSha = hash('sha256', json_encode($canonicalPlan, JSON_UNESCAPED_SLASHES));
$replayMatches = $canonicalPlan === $replayedPlan;
unset($replayedPlan, $canonicalPlan);

$serializedBytes = strlen($encoded);
unset($encoded, $compact, $view);

$receiptTotalBytes = array_sum(array_map(
    static fn(array $receipt): int => (int) strlen(json_encode($receipt['artifact']['files'] ?? array(), JSON_UNESCAPED_SLASHES)),
    $receipts
));

fwrite(STDOUT, json_encode(array(
    'fixture' => array(
        'files' => count($sharedPlan['artifact']['files'] ?? array()) + array_sum(array_map(static fn(array $r): int => count($r['artifact']['files'] ?? array()), $receipts)),
        'pages' => count($receipts),
        'receipt_files_bytes' => $receiptTotalBytes,
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
        'plan_projection_replay_matches' => $replayMatches,
        'compact_view_bytes' => $serializedBytes,
        'compact_view_sha256' => hash('sha256', (string) $serializedBytes),
    ),
    'peak_memory_bytes' => memory_get_peak_usage(true),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

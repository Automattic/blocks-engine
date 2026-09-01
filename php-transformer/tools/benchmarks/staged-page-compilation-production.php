<?php
declare(strict_types=1);

$transformerRoot = getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2);
require $transformerRoot . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

const PAGE_COUNT = 34;
const STYLESHEET_COUNT = 287;
const STYLESHEET_LINK_COUNT = 1766;
const FILE_COUNT = 624;

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

/** @return array<string,mixed> */
function productionShapedArtifact(): array
{
    $files = array();
    for ($stylesheet = 0; $stylesheet < STYLESHEET_COUNT; ++$stylesheet) {
        $files[] = array('path' => sprintf('assets/css/site-%03d.css', $stylesheet), 'content' => sprintf('.page .card-%d{margin:%dpx;padding:%dpx;color:#123;background:#f7f7f7}', $stylesheet, $stylesheet % 8, $stylesheet % 6), 'metadata' => array('compilation' => array('scope' => 'shared')));
    }
    for ($file = STYLESHEET_COUNT; $file < FILE_COUNT - PAGE_COUNT; ++$file) {
        $files[] = array('path' => sprintf('assets/media/file-%03d.png', $file), 'content_base64' => base64_encode('production-shaped-asset-' . $file), 'metadata' => array('compilation' => array('scope' => 'shared')));
    }
    for ($page = 0; $page < PAGE_COUNT; ++$page) {
        $stylesheetLinks = array();
        $linkCount = intdiv(STYLESHEET_LINK_COUNT, PAGE_COUNT) + ($page < STYLESHEET_LINK_COUNT % PAGE_COUNT ? 1 : 0);
        for ($link = 0; $link < $linkCount; ++$link) {
            $stylesheetLinks[] = sprintf('<link rel="stylesheet" href="assets/css/site-%03d.css">', ($page * 52 + $link) % STYLESHEET_COUNT);
        }
        $path = 0 === $page ? 'index.html' : sprintf('pages/page-%02d.html', $page);
        $files[] = array('path' => $path, 'content' => implode('', $stylesheetLinks) . sprintf('<main class="page card-%d"><h1>Production-shaped page %d</h1></main>', $page % STYLESHEET_COUNT, $page));
    }
    return array('entrypoints' => array('index.html'), 'compiler_limits' => array('max_files' => count($files)), 'files' => $files);
}

$artifact = isset($argv[1]) ? corpusArtifact($argv[1]) : productionShapedArtifact();
$compiler = new ArtifactCompiler();
$startedAt = hrtime(true);
$sharedPlan = $compiler->prepareShared($artifact);
$sharedMs = (hrtime(true) - $startedAt) / 1000000;
$startedAt = hrtime(true);
$pagePlans = $compiler->preparePages($artifact, $sharedPlan);
$pagesMs = (hrtime(true) - $startedAt) / 1000000;
$startedAt = hrtime(true);
$receipts = $compiler->compilePreparedPages($sharedPlan, $pagePlans);
$compileMs = (hrtime(true) - $startedAt) / 1000000;
$metrics = $compiler->htmlAnalysisCacheMetrics();
$startedAt = hrtime(true);
$result = $compiler->compose($sharedPlan, $receipts);
$composeMs = (hrtime(true) - $startedAt) / 1000000;
$output = $result->toArray();
$receiptCount = count($receipts);
$fixture = array('files' => count($artifact['files']), 'pages' => count($pagePlans), 'stylesheets' => count(array_filter($artifact['files'], static fn(array $file): bool => str_ends_with($file['path'], '.css'))));
$withoutDurations = static function (array &$value) use (&$withoutDurations): void {
    foreach ( $value as $key => &$item ) {
        if ( is_array($item) ) {
            $withoutDurations($item);
        }
        if ( str_ends_with((string) $key, '_duration_ms') ) {
            unset($value[$key]);
        }
    }
    unset($item);
};
$withoutDurations($output);
unset($receipts, $pagePlans, $sharedPlan, $artifact, $result);

fwrite(STDOUT, json_encode(array(
    'fixture' => $fixture,
    'stages_ms' => array('prepare_shared' => $sharedMs, 'prepare_pages' => $pagesMs, 'compile_prepared_pages' => $compileMs, 'compose' => $composeMs),
    'analysis_cache' => $metrics,
    'output' => array('schema' => $output['schema'] ?? null, 'receipt_count' => $receiptCount, 'canonical_sha256' => hash('sha256', json_encode($output, JSON_UNESCAPED_SLASHES))),
    'peak_memory_bytes' => memory_get_peak_usage(true),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

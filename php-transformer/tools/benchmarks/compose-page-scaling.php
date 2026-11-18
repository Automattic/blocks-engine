<?php
declare(strict_types=1);

/**
 * Compose-time scaling on a synthetic N-page artifact.
 *
 * Builds an N-page site from a checked-in multi-page website fixture by
 * cloning its non-entry pages (each clone gets a distinct title, heading and
 * nav link so no two pages are byte-identical), compiles the pages once
 * through the staged path, then times compose() alone. Each size reports the
 * compose wall time, time per page, and the slowest compose sub-steps from
 * the result's step_timings, so superlinear growth shows up as a rising
 * ms/page column and names the step that owns it.
 *
 * Usage: php compose-page-scaling.php [fixture-dir] [sizes]
 *   fixture-dir defaults to fixtures/websites/28-enterprise-b2b
 *   sizes is a comma list of page counts, default 10,20,40
 * COMPOSE_SCALING_PAGE_CSS_RULES=<n> gives every page n page-owned author
 * rules (with a media variant) so total author CSS grows with the page count.
 * COMPOSE_SCALING_ARTIFACT_OUT=<dir> writes each synthetic artifact as JSON,
 * the input staged-compose-profile.php accepts for stack sampling.
 * COMPOSE_SCALING_PLAN_OUT=<dir> writes each size's canonical plan hash so a
 * baseline and candidate tree can be compared for identical output.
 */

$transformerRoot = getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2);
require $transformerRoot . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = ($argv[1] ?? '') ?: dirname(__DIR__, 3) . '/fixtures/websites/28-enterprise-b2b';
$sizes = array_map('intval', explode(',', $argv[2] ?? '10,20,40'));

/** @return array<string,string> path => content */
function fixtureFiles(string $root): array
{
    $root = rtrim(realpath($root) ?: $root, '/') . '/';
    $files = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && 'fixture.json' !== $file->getFilename()) {
            $files[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

/** @return array<string,mixed> */
function syntheticArtifact(array $sourceFiles, int $pages, int $pageCss): array
{
    $templates = array_values(array_filter(array_keys($sourceFiles), static fn(string $path): bool => str_ends_with($path, '.html') && 'index.html' !== $path));
    $clones = array();
    for ($i = 0; count($clones) < $pages - 1; ++$i) {
        $template = $templates[$i % count($templates)];
        $clones['p' . $i . '-' . $template] = $template;
    }
    $navLinks = implode('', array_map(static fn(string $path): string => '<li><a href="' . $path . '">' . $path . '</a></li>', array_keys($clones)));
    $files = array();
    foreach ($sourceFiles as $path => $content) {
        if (!str_ends_with($path, '.html') || 'index.html' === $path) {
            if (!str_ends_with($path, '.html')) $files[] = array('path' => $path, 'content' => $content);
            continue;
        }
    }
    $files[] = array('path' => 'index.html', 'content' => str_replace('</footer>', '<ul class="synthetic-sitemap">' . $navLinks . '</ul></footer>', $sourceFiles['index.html']));
    $i = 0;
    foreach ($clones as $path => $template) {
        ++$i;
        $content = preg_replace('~<title>(.*?)</title>~s', '<title>$1 ' . $path . '</title>', $sourceFiles[$template], 1);
        $content = preg_replace('~(<h1[^>]*>)~', '$1' . $path . ' ', (string) $content, 1);
        if ($pageCss > 0) {
            // Page-owned author CSS, as a captured builder page carries: total
            // stylesheet bytes then grow with the page count.
            $rules = '';
            $markup = '';
            for ($r = 0; $r < $pageCss; ++$r) {
                $class = 'syn-p' . $i . '-' . $r;
                $rules .= '.' . $class . '{color:#' . substr(md5($class), 0, 6) . ';padding:' . ($r % 7) . 'px ' . ($r % 5) . 'px;margin-top:' . ($r % 3) . 'rem}'
                    . '@media (max-width:' . (600 + $r % 4 * 100) . 'px){.' . $class . '{font-size:' . (12 + $r % 9) . 'px}}';
                $markup .= '<p class="' . $class . '">Page ' . $i . ' paragraph ' . $r . '</p>';
            }
            $content = str_replace('</head>', '<style>' . $rules . '</style></head>', (string) $content);
            $content = preg_replace('~(</h1>)~', '$1<div class="syn-page-' . $i . '">' . $markup . '</div>', (string) $content, 1);
        }
        $files[] = array('path' => $path, 'content' => (string) $content);
    }
    usort($files, static fn(array $l, array $r): int => strcmp($l['path'], $r['path']));
    return array('entrypoints' => array('index.html'), 'compiler_limits' => array('max_files' => count($files) + 10, 'max_total_bytes' => 512 * 1024 * 1024), 'files' => $files);
}

$pageCss = (int) (getenv('COMPOSE_SCALING_PAGE_CSS_RULES') ?: 0);
$sourceFiles = fixtureFiles($fixture);
$rows = array();
foreach ($sizes as $size) {
    $compiler = new ArtifactCompiler();
    $artifact = syntheticArtifact($sourceFiles, $size, $pageCss);
    if (getenv('COMPOSE_SCALING_ARTIFACT_OUT')) {
        file_put_contents(rtrim((string) getenv('COMPOSE_SCALING_ARTIFACT_OUT'), '/') . '/artifact-' . $size . '.json', json_encode($artifact, JSON_UNESCAPED_SLASHES));
    }
    $shared = $compiler->prepareShared($artifact);
    $receipts = $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared));
    unset($artifact);
    $started = hrtime(true);
    $result = $compiler->compose($shared, $receipts);
    $ms = (hrtime(true) - $started) / 1e6;
    $steps = $result->metrics['step_timings'] ?? array();
    usort($steps, static fn(array $l, array $r): int => $r['ms'] <=> $l['ms']);
    $plan = $result->sourceReports['wordpress_site_plan'] ?? array();
    $hash = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (getenv('COMPOSE_SCALING_PLAN_OUT')) {
        file_put_contents(rtrim((string) getenv('COMPOSE_SCALING_PLAN_OUT'), '/') . '/plan-' . $size . '.sha256', $hash . "\n");
    }
    $rows[] = array(
        'pages' => count($receipts),
        'compose_ms' => round($ms, 1),
        'ms_per_page' => round($ms / max(1, count($receipts)), 1),
        'plan_sha256' => $hash,
        'top_steps' => array_map(static fn(array $step): array => array('step' => $step['step'], 'ms' => round($step['ms'], 1)), array_slice($steps, 0, 5)),
    );
    fwrite(STDOUT, json_encode(end($rows), JSON_UNESCAPED_SLASHES) . "\n");
    unset($result, $receipts, $shared, $compiler);
    gc_collect_cycles();
}

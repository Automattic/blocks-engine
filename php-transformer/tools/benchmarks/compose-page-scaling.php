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
 * fixture-dir `synthetic` instead builds a neutral site (no brand content):
 * every route links one large shared stylesheet of
 * COMPOSE_SCALING_SHARED_CSS_RULES rules (default 2000) and carries the
 * same shell, one of four body templates and a small per-route delta, so a
 * cost that grows with routes x shared rules shows up as rising ms/page.
 * Each row also reports author_stylesheet_projections_ms, the summed
 * compose.author_stylesheet_projections.* steps.
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
$sharedCssRules = (int) (getenv('COMPOSE_SCALING_SHARED_CSS_RULES') ?: 2000);
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

/**
 * A neutral site: one shared stylesheet with `$rules` rules over a shared
 * shell and four body templates, linked by every page.
 *
 * @return array<string,string> path => content
 */
function neutralFiles(int $rules): array
{
    // Per-route delta rules lead the sheet, so every later shared rule that
    // sets one of their families must be proven not to compete on each route.
    $css = '';
    for ($d = 0; $d < 256; ++$d) {
        $css .= '.delta-' . $d . '{color:#' . substr(md5('d' . $d), 0, 6) . ';margin:' . ($d % 5) . 'px;padding:1px;border-color:red;font-size:13px;background:#fff}';
    }
    for ($r = 0; $r < $rules; ++$r) {
        $color = substr(md5('u' . $r), 0, 6);
        $css .= match ($r % 5) {
            0 => '.u-' . $r . '{color:#' . $color . ';margin:' . ($r % 7) . 'px}',
            1 => '.shell .nav-' . ($r % 40) . ' a.u-' . ($r % 400) . '{padding:' . ($r % 9) . 'px}',
            2 => '.sec-' . ($r % 20) . ' p,.site-footer > .u-' . ($r % 400) . '{border-color:#' . $color . '}',
            3 => '@media (max-width:' . (600 + $r % 4 * 100) . 'px){.card-' . ($r % 50) . ' .u-' . ($r % 400) . '{font-size:' . (12 + $r % 9) . 'px}}',
            default => '.u-' . ($r % 400) . ':hover,.card-' . ($r % 50) . ':focus-within .u-' . ($r % 400) . '{background:#' . $color . '}',
        };
    }
    $nav = '';
    for ($n = 0; $n < 40; ++$n) $nav .= '<li class="nav-' . $n . '"><a class="u-' . ($n * 10) . '" href="#n' . $n . '">Item ' . $n . '</a></li>';
    $footer = '';
    for ($f = 0; $f < 300; ++$f) $footer .= '<p class="u-' . ($f * 5 + 1) . '">Footer line ' . $f . '</p>';
    $files = array('css/site.css' => $css);
    for ($t = 0; $t < 4; ++$t) {
        $body = '';
        for ($s = 0; $s < 10; ++$s) {
            $section = ($s * 2 + $t) % 20;
            $body .= '<section class="sec-' . $section . '"><h2>Section ' . $s . '</h2>';
            for ($c = 0; $c < 5; ++$c) {
                $card = ($section * 5 + $c + $t) % 50;
                $body .= '<div class="card-' . $card . '"><p class="u-' . (($card * 8 + $t) % 400) . '">Card text ' . $c . '</p><span class="u-' . (($card * 8 + 3) % 400) . '">Note</span></div>';
            }
            $body .= '</section>';
        }
        $files['template-' . $t . '.html'] = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Template ' . $t . '</title><link rel="stylesheet" href="css/site.css"></head><body>'
            . '<header class="shell"><nav><ul>' . $nav . '</ul></nav></header><main><h1>Template ' . $t . '</h1><!--route-delta-->' . $body . '</main>'
            . '<footer class="shell site-footer">' . $footer . '</footer></body></html>';
    }
    $files['index.html'] = str_replace('Template 0', 'Home', $files['template-0.html']);
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
        $content = str_replace('<!--route-delta-->', '<div class="route-delta"><p>Route ' . $i . '</p><span class="delta-' . ($i % 256) . '">delta</span></div>', (string) $content);
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
$sourceFiles = 'synthetic' === $fixture ? neutralFiles($sharedCssRules) : fixtureFiles($fixture);
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
    $projectionMs = array_sum(array_map(static fn(array $step): float => str_starts_with($step['step'], 'compose.author_stylesheet_projections') ? (float) $step['ms'] : 0.0, $steps));
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
        'author_stylesheet_projections_ms' => round($projectionMs, 1),
        'plan_sha256' => $hash,
        'top_steps' => array_map(static fn(array $step): array => array('step' => $step['step'], 'ms' => round($step['ms'], 1)), array_slice($steps, 0, 5)),
    );
    fwrite(STDOUT, json_encode(end($rows), JSON_UNESCAPED_SLASHES) . "\n");
    unset($result, $receipts, $shared, $compiler);
    gc_collect_cycles();
}

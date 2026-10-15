<?php
/**
 * Shared-chrome corpus harness.
 *
 * Compiles multi-route website directories through ArtifactCompiler and its
 * public WordPress site-plan projection, then reports emitted shell entities
 * and page-content residue. Pure PHP: no WordPress runtime, browser, or network.
 * The core/html metric describes the Blocks Engine plan, not the final import:
 * on soscalvaires.org, BE leaves its form as core/html (8 blocks), which SSI
 * later converts during import.
 *
 * Usage: php tools/shared-chrome-corpus/run.php [--corpus <dir>] [--fixture <id>]
 *        [--capture <dir> ...] [--json]
 *
 * --corpus <dir>  Website fixture corpus (default: repo fixtures/websites plus
 *                 the canonical shared-chrome regression fixture). Directories
 *                 and zip fixtures with at least two HTML routes are included.
 * --fixture <id>  Run one fixture/capture by directory name.
 * --capture <dir> Importable capture; websiteRoot comes from
 *                 capture-receipt.json (default: website). Repeatable; explicit
 *                 captures with one route are reported too.
 * --json          Emit the deterministic machine-readable report.
 */
declare(strict_types=1);

ini_set('memory_limit', '2G');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$options = array();
$captures = array();
for ($i = 1; $i < count($argv); $i++) {
    if ('--json' === $argv[$i]) { $options['json'] = true; continue; }
    if (in_array($argv[$i], array('--corpus', '--fixture', '--capture'), true) && isset($argv[$i + 1])) {
        $key = substr($argv[$i], 2);
        $value = $argv[++$i];
        if ('capture' === $key) $captures[] = $value; else $options[$key] = $value;
    }
}
$root = dirname(__DIR__, 3);
$inputs = array();
$supplementalFixtures = array();
$corpus = $options['corpus'] ?? ( $captures ? null : $root . '/fixtures/websites' );
if (null !== $corpus) {
    foreach ((array) glob(rtrim($corpus, '/') . '/*', GLOB_ONLYDIR) as $dir) $inputs[basename($dir)] = $dir;
    if (class_exists(ZipArchive::class)) foreach ((array) glob(rtrim($corpus, '/') . '/*.zip') as $archive) $inputs[substr(basename($archive), 0, -4)] = $archive;
}
if (!$captures) {
    $fixtureRoot = dirname(__DIR__, 2) . '/tests/fixtures';
    if (!isset($options['corpus'])) $inputs['canonical-shared-chrome'] = $fixtureRoot . '/canonical-shared-chrome';
    foreach (array('nested-header-menu' => 'nested-header-menu.php', 'viewport-hidden-wix-mobile-document' => 'viewport-hidden-wix-mobile-document.html') as $fixtureId => $filename) {
        $inputs[$fixtureId] = $fixtureRoot . '/' . $filename;
        $supplementalFixtures[] = $fixtureId;
    }
}
foreach ($captures as $capture) $inputs[basename(rtrim($capture, '/'))] = $capture;
if (isset($options['fixture'])) $inputs = array_intersect_key($inputs, array($options['fixture'] => true));
$results = array();
foreach ($inputs as $id => $dir) {
    $siteRoot = $dir;
    $receipt = array();
    if (is_file($siteRoot) && str_ends_with(strtolower($siteRoot), '.zip') && !class_exists(ZipArchive::class)) {
        fwrite(STDERR, "Cannot read fixture archive without ext-zip: {$siteRoot}\n");
        continue;
    }
    if (is_file($dir . '/capture-receipt.json')) {
        $decodedReceipt = json_decode((string) file_get_contents($dir . '/capture-receipt.json'), true);
        $receipt = is_array($decodedReceipt) ? $decodedReceipt : array();
        $siteRoot = $dir . '/' . (is_string($receipt['websiteRoot'] ?? null) ? $receipt['websiteRoot'] : 'website');
    }
    $files = array();
    if (is_file($siteRoot) && str_ends_with(strtolower($siteRoot), '.zip')) {
        $archive = new ZipArchive();
        if (true === $archive->open($siteRoot)) {
            $prefix = basename($siteRoot, '.zip') . '/';
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $path = str_replace('\\', '/', (string) $archive->getNameIndex($index));
                if (!str_starts_with($path, $prefix) || '' === $path || str_ends_with($path, '/') || str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) continue;
                $path = substr($path, strlen($prefix));
                $content = $archive->getFromIndex($index);
                if (is_string($content)) $files[$path] = $content;
            }
            $archive->close();
        }
    } elseif (is_file($siteRoot)) {
        $content = 'php' === strtolower(pathinfo($siteRoot, PATHINFO_EXTENSION)) ? require $siteRoot : file_get_contents($siteRoot);
        if (is_string($content)) $files['index.html'] = $content;
    } else {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($siteRoot, '/')) + 1));
            $files[$path] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files);
    $routeFiles = array_values(array_filter(array_keys($files), static fn(string $path): bool => str_ends_with(strtolower($path), '.html') && !str_starts_with($path, 'parts/')));
    if (!$routeFiles) continue;
    if (count($routeFiles) < 2 && !in_array($dir, $captures, true) && !in_array($id, $supplementalFixtures, true)) continue;
    $entrypoint = is_string($receipt['entrypoint'] ?? null) ? $receipt['entrypoint'] : '';
    foreach (array((string) ($receipt['websiteRoot'] ?? 'website') . '/', 'website/') as $prefix) if (str_starts_with($entrypoint, $prefix)) { $entrypoint = substr($entrypoint, strlen($prefix)); break; }
    $entry = in_array($entrypoint, $routeFiles, true) ? $entrypoint : (in_array('index.html', $routeFiles, true) ? 'index.html' : $routeFiles[0]);
    // Media travels as payload references, as Static Site Importer sends it:
    // the shell decision reads markup and styles, never binary bytes.
    $artifactFiles = array();
    $mimeDetector = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files as $path => $content) {
        $mime = (string) $mimeDetector->buffer($content);
        $text = str_starts_with($mime, 'text/') || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('html', 'htm', 'css', 'js', 'mjs', 'json', 'svg', 'xml', 'txt'), true);
        $artifactFiles[] = $text
            ? array('path' => 'website/' . $path, 'content' => $content)
            : array('path' => 'website/' . $path, 'mime_type' => $mime, 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)));
    }
    try {
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/' . $entry, 'files' => $artifactFiles))->toWordPressSitePlanView();
    } catch (InvalidArgumentException $error) {
        $receiptRoutes = is_array($receipt['routes'] ?? null) ? $receipt['routes'] : array();
        $routeNames = array_values(array_filter(array_map(static fn(array $route): string => (string) ($route['path'] ?? ''), $receiptRoutes)));
        if (!$routeNames) $routeNames = array_map(static fn(string $path): string => 'website/' . $path, $routeFiles);
        $results[] = array(
            'site' => $id,
            'status' => 'compile_failed',
            'compile_error' => mb_strcut(preg_replace('/\s+/', ' ', $error->getMessage()) ?? get_class($error), 0, 300, 'UTF-8'),
            'routes' => $routeNames,
            'route_count' => count($routeNames),
            'parts' => array(),
            'templates' => array(),
            'route_residue' => array(),
            'routes_with_navigation_in_content' => null,
            'routes_with_repeated_edge_residue' => null,
            'wp_navigation_count' => null,
            'distinct_normalized_link_sets' => null,
            'core_html_fallback_count' => null,
            'shell_diagnostic_codes' => array(),
        );
        continue;
    }
    $plan = $result['wordpress_site_plan'] ?? array();
    $pageRows = $plan['pages'] ?? array();
    if (count($pageRows) < 2 && !in_array($dir, $captures, true) && !in_array($id, $supplementalFixtures, true)) continue;
    $parts = array();
    foreach (($plan['template_parts'] ?? array()) as $part) $parts[] = array('slug' => $part['slug'] ?? '', 'area' => $part['area'] ?? '', 'routes' => array());
    $templates = array_values(array_map(static fn(array $row): string => (string) ($row['slug'] ?? ''), $plan['templates'] ?? array()));
    $templateMarkup = array_column($plan['templates'] ?? array(), 'canonical_block_markup', 'slug');
    $navInContent = $fallback = 0;
    $edgeIdentities = array();
    $routeNames = array();
    $runtime = new Runtime();
    foreach ($pageRows as $page) {
        $markup = (string) ($page['canonical_block_markup'] ?? '');
        $routeNames[] = (string) ($page['source_path'] ?? '');
        if (str_contains($markup, 'wp:navigation')) $navInContent++;
        $fallback += preg_match_all('/<!--\s*wp:html\b/', $markup);
        $topBlocks = array_values(array_filter($runtime->parseBlocks($markup), static fn(array $block): bool => is_string($block['blockName'] ?? null)));
        if ($topBlocks) {
            $edgeIdentities[] = array(
                'leading' => blockIdentity($runtime->serializeBlocks(array($topBlocks[0]))),
                'trailing' => blockIdentity($runtime->serializeBlocks(array($topBlocks[count($topBlocks) - 1]))),
            );
        } else $edgeIdentities[] = array('leading' => null, 'trailing' => null);
    }
    $repeatedRouteIndexes = array();
    foreach (array('leading', 'trailing') as $edge) {
        $groups = array();
        foreach ($edgeIdentities as $index => $identity) if (null !== $identity[$edge]) $groups[$identity[$edge]][] = $index;
        foreach ($groups as $indexes) if (count($indexes) > 1) foreach ($indexes as $index) $repeatedRouteIndexes[$index] = true;
    }
    $routeResidue = array();
    foreach ($pageRows as $index => $page) {
        $sourcePath = (string) ($page['source_path'] ?? '');
        $containsNavigation = str_contains((string) ($page['canonical_block_markup'] ?? ''), 'wp:navigation');
        $hasRepeatedEdge = isset($repeatedRouteIndexes[$index]);
        $routeResidue[] = array('route' => $sourcePath, 'navigation_in_content' => $containsNavigation, 'repeated_edge_residue' => $hasRepeatedEdge);
        $templateSlug = !empty($page['entrypoint']) ? 'front-page' : ('page-' . (string) ($page['slug'] ?? ''));
        if (!isset($templateMarkup[$templateSlug])) $templateSlug = 'page';
        foreach ($parts as $partIndex => $part) {
            if (preg_match('/<!--\s*wp:template-part\s+\{[^}]*"slug":"' . preg_quote((string) $part['slug'], '/') . '"/', (string) ($templateMarkup[$templateSlug] ?? ''))) $parts[$partIndex]['routes'][] = $sourcePath;
        }
    }
    $menus = $plan['menus'] ?? array();
    $menuSets = array();
    foreach ($menus as $menu) {
        $links = array();
        if (preg_match_all('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*(?:\/)?-->/s', (string) ($menu['block_markup'] ?? ''), $matches)) {
            foreach ($matches[1] as $attributes) {
                $attrs = json_decode($attributes, true);
                if (is_array($attrs)) $links[] = array('label' => (string) ($attrs['label'] ?? ''), 'url' => (string) ($attrs['url'] ?? ''));
            }
        }
        usort($links, static fn(array $a, array $b): int => strcmp($a['url'] . "\0" . $a['label'], $b['url'] . "\0" . $b['label']));
        $menuSets[hash('sha256', json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')] = true;
    }
    $diagnostics = array();
    foreach (array($result['diagnostics'] ?? array(), $plan['diagnostics'] ?? array()) as $diagnosticRows) foreach ($diagnosticRows as $diagnostic) if (is_array($diagnostic) && is_string($diagnostic['code'] ?? null) && str_starts_with($diagnostic['code'], 'wordpress_site_plan_shell_')) $diagnostics[] = $diagnostic['code'];
    $diagnostics = array_values(array_unique($diagnostics));
    sort($diagnostics);
    $results[] = array('site' => $id, 'status' => 'compiled', 'routes' => $routeNames, 'route_count' => count($pageRows), 'parts' => $parts, 'templates' => $templates, 'route_residue' => $routeResidue, 'routes_with_navigation_in_content' => $navInContent, 'routes_with_repeated_edge_residue' => count($repeatedRouteIndexes), 'wp_navigation_count' => count($menus), 'distinct_normalized_link_sets' => count($menuSets), 'core_html_fallback_count' => $fallback, 'shell_diagnostic_codes' => $diagnostics);
}
usort($results, static fn(array $a, array $b): int => strcmp($a['site'], $b['site']));
$report = array('schema' => 'blocks-engine/php-transformer/shared-chrome-corpus/v1', 'site_count' => count($results), 'results' => $results);
if (isset($options['json'])) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (false === $json) { fwrite(STDERR, "Failed to encode the shared-chrome report as JSON.\n"); exit(1); }
    fwrite(STDOUT, $json . "\n");
    exit(0);
}
fwrite(STDOUT, sprintf("Shared-chrome corpus — %d site(s)\n%-26s %-13s %5s %5s %5s %5s %5s %6s %5s %5s %5s\n", count($results), 'site', 'status', 'routes', 'parts', 'tmpl', 'nav', 'edge', 'wp_nav', 'sets', 'html', 'codes'));
foreach ($results as $row) fwrite(STDOUT, sprintf("%-26s %-13s %5d %5d %5d %5s %5s %6s %5s %5s %5d\n", $row['site'], $row['status'], $row['route_count'], count($row['parts']), count($row['templates']), $row['routes_with_navigation_in_content'] ?? '—', $row['routes_with_repeated_edge_residue'] ?? '—', $row['wp_navigation_count'] ?? '—', $row['distinct_normalized_link_sets'] ?? '—', $row['core_html_fallback_count'] ?? '—', count($row['shell_diagnostic_codes'])));

function blockIdentity(string $markup): string
{
    $markup = ShellExtraction::identityMarkup(ShellExtraction::withoutCurrentNavigationState($markup, true));
    $markup = preg_replace('/\b(?:id|anchor)="[^"]*"/i', '', $markup) ?? $markup;
    $markup = preg_replace('/\{\{(?:wordpress-site-plan|blocks-engine):[^}]+\}\}/', '{{token}}', $markup) ?? $markup;
    return hash('sha256', preg_replace('/\s+/', ' ', trim($markup)) ?? trim($markup));
}

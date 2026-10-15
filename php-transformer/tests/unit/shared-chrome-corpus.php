<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$fixture = __DIR__ . '/../fixtures/canonical-shared-chrome/';
$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) if ($file->isFile()) $files[substr($file->getPathname(), strlen($fixture))] = (string) file_get_contents($file->getPathname());
$artifactFiles = array();
foreach ($files as $path => $content) $artifactFiles['website/' . $path] = array('path' => 'website/' . $path, 'content' => $content);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $artifactFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
$partSlugs = array_values(array_unique(array_column($plan['template_parts'], 'slug')));
sort($partSlugs);
$assert(array('footer', 'header') === $partSlugs, 'Canonical shared-chrome emits its expected header and footer parts.');

$temp = sys_get_temp_dir() . '/be-shared-chrome-' . getmypid();
mkdir($temp);
file_put_contents($temp . '/index.html', '<header><p>Shared</p></header><main><h1>Home</h1></main>');
file_put_contents($temp . '/about.html', '<header><p>Shared</p></header><main><h1>About</h1></main>');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/shared-chrome-corpus/run.php') . ' --capture ' . escapeshellarg($temp) . ' --json';
$first = shell_exec($command);
$second = shell_exec($command);
unlink($temp . '/index.html'); unlink($temp . '/about.html'); rmdir($temp);
$assert(is_string($first) && $first !== '' && $first === $second, 'Harness JSON is byte-identical across repeated runs.');
$report = json_decode($first, true);
$assert('compiled' === ($report['results'][0]['status'] ?? null) && 2 === ($report['results'][0]['route_count'] ?? null), 'Harness route count comes from the compiled plan pages.');
$assert(0 === ($report['results'][0]['routes_with_repeated_edge_residue'] ?? null), 'Distinct page content does not produce false repeated-edge residue after shared chrome is extracted.');
fwrite(STDOUT, "Shared-chrome corpus test passed.\n");

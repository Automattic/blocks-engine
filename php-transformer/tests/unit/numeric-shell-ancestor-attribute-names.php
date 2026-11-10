<?php
declare(strict_types=1);

/**
 * Numeric ancestor attribute names become int array keys in PHP. Shared-shell
 * serialization must skip them rather than pass them to preg_match().
 * See blocks-engine#5598.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$fixture = '<!doctype html><html><head><title>Home</title><style>header{padding:1rem;background:#eee}</style></head><body 0="" class="site"><header><nav><a href="/">Home</a> <a href="/about/">About</a></nav></header><main><h1>Home</h1><p>Page body.</p></main></body></html>';
$files = array(
    array('path' => 'index.html', 'content' => $fixture),
    array('path' => 'about/index.html', 'content' => str_replace(array('<title>Home</title>', '<h1>Home</h1>'), array('<title>About</title>', '<h1>About</h1>'), $fixture)),
);
$artifact = array('entrypoints' => array('index.html'), 'files' => $files);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$compiled = array();
foreach ($files as $file) $compiled[$file['path']] = $compiler->compilePage($artifact, $shared, $file['path']);
$result = $compiler->compose($shared, $compiled)->toArray();

$assert(str_starts_with((string) ($result['status'] ?? ''), 'success'), 'The two-page staged compose completes without a TypeError.', (string) ($result['status'] ?? ''));
$headers = array_filter(
    $result['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(),
    static fn (array $shell): bool => 'header' === ($shell['area'] ?? null) && 2 === count($shell['source_paths'] ?? array())
);
$assert(array() !== $headers, 'A shared header shell artifact exists for both pages.');

if (0 < $failures) {
    fwrite(STDERR, "numeric shell ancestor attribute names FAILED: {$failures} failed\n");
    exit(1);
}
echo "numeric shell ancestor attribute names passed\n";

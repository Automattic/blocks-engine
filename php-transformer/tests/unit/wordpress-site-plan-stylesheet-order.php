<?php
declare(strict_types=1);

/** Linked stylesheet source order must survive a path-ordered asset inventory. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="styles/z-first.css"><link rel="stylesheet" href="styles/a-second.css"></head><body><main><div class="article"><p>Article</p></div></main></body></html>',
        'styles/z-first.css' => '.article{flex-basis:100%;max-width:100%}',
        'styles/a-second.css' => '.article{flex-basis:66.6667%;max-width:66.6667%}',
    ),
))->toArray();

// Model the path-keyed compiled-site inventory that a producer may hand to
// WordPressSitePlan: its lexical file order differs from the document's links.
$compiledAssets = $result['source_reports']['compiled_site']['assets'] ?? array();
$stylesheetAssets = array_values(array_filter($compiledAssets, static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)));
$otherAssets = array_values(array_filter($compiledAssets, static fn(array $asset): bool => 'css' !== ($asset['kind'] ?? null)));
usort($stylesheetAssets, static fn(array $left, array $right): int => strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? '')));
$result['source_reports']['compiled_site']['assets'] = array_merge($stylesheetAssets, $otherAssets);

$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$cssPaths = array_values(array_map(
    static fn(array $asset): string => (string) ($asset['source_path'] ?? ''),
    array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))
));
$expected = array('styles/z-first.css', 'styles/a-second.css');
$assert($expected === $cssPaths, 'compiled plan restores linked stylesheet order rather than path order', implode(' -> ', $cssPaths));

$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) {
        $bootstrap = (string) ($write['payload']['data'] ?? '');
        break;
    }
}
$firstEnqueue = strpos($bootstrap, 'assets/styles/z-first.css');
$secondEnqueue = strpos($bootstrap, 'assets/styles/a-second.css');
$assert(false !== $firstEnqueue && false !== $secondEnqueue && $firstEnqueue < $secondEnqueue, 'generated theme bootstrap enqueues linked stylesheets in source order', $bootstrap);

if ($failures > 0) {
    fwrite(STDERR, "WordPress site-plan stylesheet order: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "WordPress site-plan stylesheet order passed: {$passes} assertions\n");

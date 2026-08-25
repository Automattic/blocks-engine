<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = '<!doctype html><html><head>' . str_repeat('<style>.x{color:red}</style>', 100) . '<link rel="preload" href="/_runtimes/site.js" as="script"></head><body></body></html>';
$normalizer = new ArtifactNormalizer();
$result = $normalizer->normalize(array(
    'entrypoint' => 'website/index.html',
    'compiler_limits' => array('max_files' => 100),
    'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'website/_runtimes/site.js', 'content' => 'export const site = true;'),
    ),
));

$paths = array_column($result['files'], 'path');
$assert(in_array('website/index.html', $paths, true), 'The declared entrypoint retains admission priority.');
$assert(in_array('website/_runtimes/site.js', $paths, true), 'A declared runtime asset cannot be starved by generated inline expansions.');
$assert(count($result['files']) === 100, 'The configured file limit remains enforced.');
$assert(in_array('file_limit_exceeded', array_column($result['diagnostics'], 'code'), true), 'Generated overflow remains observable.');
$impact = $result['truncation_impact'];
$assert('warning' === ($impact['completeness'] ?? null), 'Unreferenced generated omissions remain warning-level.');
$assert(2 === ($impact['omitted_count'] ?? null), 'Generated overflow reports the complete omitted file count.');
$assert(2 === ($impact['omitted_by_source_class']['generated']['count'] ?? null), 'Generated overflow is classified separately from source files.');
$assert(0 === ($impact['reference_reachability']['referenced_omitted_count'] ?? null), 'Unreferenced generated omissions report no reachable references.');
$assert(64 === strlen((string) ($impact['evidence_hash'] ?? '')), 'Truncation evidence has a bounded SHA-256 digest.');
$assert($impact === $normalizer->normalize(array(
    'entrypoint' => 'website/index.html',
    'compiler_limits' => array('max_files' => 100),
    'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'website/_runtimes/site.js', 'content' => 'export const site = true;'),
    ),
))['truncation_impact'], 'Truncation evidence has deterministic ordering and content.');

$referenced = $normalizer->normalize(array(
    'compiler_limits' => array('max_files' => 2),
    'files' => array(
        array('path' => 'index.html', 'content' => '<main><img src="assets/logo.svg"></main>'),
        array('path' => 'assets/site.css', 'content' => 'main{display:block}'),
        array('path' => 'assets/logo.svg', 'content' => '<svg/>'),
    ),
));
$referencedImpact = $referenced['truncation_impact'];
$assert('gating_loss' === ($referencedImpact['completeness'] ?? null), 'An admitted canonical write referencing an omitted file is an explicit gating loss.');
$assert(1 === ($referencedImpact['reference_reachability']['referenced_omitted_count'] ?? null), 'Referenced omissions report reachable omitted files.');
$assert('assets/logo.svg' === ($referencedImpact['reference_reachability']['reference_samples'][0]['resolved_path'] ?? null), 'Referenced omission samples identify the omitted target deterministically.');

$duplicate = $normalizer->normalize(array(
    'compiler_limits' => array('max_files' => 2),
    'files' => array(
        array('path' => 'index.html', 'content' => '<main><img src="assets/logo.svg"></main>'),
        array('path' => 'assets/logo.svg', 'content' => '<svg id="admitted"/>'),
        array('path' => 'assets/logo.svg', 'content' => '<svg id="omitted-duplicate"/>'),
    ),
));
$duplicateImpact = $duplicate['truncation_impact'];
$assert('warning' === ($duplicateImpact['completeness'] ?? null), 'A truncated duplicate does not shadow an admitted canonical asset as a gating loss.');
$assert('assets/logo-2.svg' === ($duplicateImpact['omitted_path_samples'][0]['path'] ?? null), 'Truncated duplicate paths use the canonical deduplicated artifact namespace.');
$assert(0 === ($duplicateImpact['reference_reachability']['referenced_omitted_count'] ?? null), 'References resolve against the admitted canonical asset, not the omitted duplicate.');

$largeJson = '{"payload":"' . str_repeat('x', 9 * 1024 * 1024) . '"}' . "\n";
memory_reset_peak_usage();
$memoryBefore = memory_get_usage(true);
$largeJsonResult = $normalizer->normalize(array(
    'compiler_limits' => array('max_file_bytes' => 10 * 1024 * 1024, 'max_total_bytes' => 20 * 1024 * 1024),
    'files' => array(array('path' => 'capture.json', 'content' => $largeJson, 'mime_type' => 'application/json')),
));
$largeJsonPeakDelta = memory_get_peak_usage(true) - $memoryBefore;
$assert(1 === count($largeJsonResult['files']) && $largeJsonPeakDelta < 16 * 1024 * 1024, 'Large non-HTML payloads bypass inline-style inspection without artifact-sized normalization copies.');
unset($largeJson, $largeJsonResult);

$qualityResult = (new ArtifactCompiler())->compile(array(
    'compiler_limits' => array('max_files' => 2),
    'files' => array(
        array('path' => 'index.html', 'content' => '<main><img src="assets/logo.svg"></main>'),
        array('path' => 'assets/site.css', 'content' => 'main{display:block}'),
        array('path' => 'assets/logo.svg', 'content' => '<svg/>'),
    ),
))->toArray();
$assert('gating_loss' === ($qualityResult['source_reports']['artifact']['truncation_impact']['completeness'] ?? null), 'Quality consumers receive the reachable truncation gating loss in the artifact report.');

echo "artifact normalizer source budget: ok\n";

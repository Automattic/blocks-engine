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
$canResetPeak = function_exists('memory_reset_peak_usage');
if ($canResetPeak) memory_reset_peak_usage();
$memoryBefore = memory_get_usage(true);
$largeJsonResult = $normalizer->normalize(array(
    'compiler_limits' => array('max_file_bytes' => 10 * 1024 * 1024, 'max_total_bytes' => 20 * 1024 * 1024),
    'files' => array(array('path' => 'capture.json', 'content' => $largeJson, 'mime_type' => 'application/json')),
));
$largeJsonPeakDelta = memory_get_peak_usage(true) - $memoryBefore;
$assert(1 === count($largeJsonResult['files']) && (!$canResetPeak || $largeJsonPeakDelta < 16 * 1024 * 1024), 'Large non-HTML payloads bypass inline-style inspection without artifact-sized normalization copies.');
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

$oversizedResult = (new ArtifactCompiler())->compile(array(
    'compiler_limits' => array('max_file_bytes' => ArtifactNormalizer::MAX_FILE_BYTES),
    'files' => array(
        array('path' => 'index.html', 'content' => '<main>Accepted</main>'),
        array('path' => 'evidence.json', 'content' => str_repeat('x', 16230577), 'role' => 'evidence', 'type' => 'json'),
    ),
))->toArray();
$rejectionDiagnostic = null;
foreach ($oversizedResult['diagnostics'] as $diagnostic) {
    if ('artifact_inputs_rejected' === ($diagnostic['code'] ?? null)) {
        $rejectionDiagnostic = $diagnostic;
        break;
    }
}
$rejectionContext = $rejectionDiagnostic['context'] ?? array();
$assert('success_with_warnings' === $oversizedResult['status'] && 1 === ($rejectionContext['rejected_count'] ?? null) && 1 === ($rejectionContext['rejected_by_code']['artifact_file_too_large'] ?? null), 'An ordinary compile persists a bounded final warning for an oversized artifact input.');
$assert(array('code' => 'artifact_file_too_large', 'path' => 'evidence.json', 'bytes' => 16230577, 'declared_type' => 'json') === ($rejectionContext['samples'][0] ?? null) && 0 === ($rejectionContext['samples_omitted'] ?? null), 'The final warning retains only bounded generic artifact facts, not arbitrary declared metadata or rejected payload content.');

$manyDroppedFiles = array(array('path' => 'index.html', 'content' => '<main>Accepted</main>'));
for ($index = 0; $index < 12; ++$index) $manyDroppedFiles[] = array('path' => 'ancillary-' . $index . '.json', 'content' => '{}', 'role' => 'evidence', 'type' => 'json');
$manyDropped = $normalizer->normalize(array('compiler_limits' => array('max_files' => 1), 'files' => $manyDroppedFiles));
$manyDroppedSummary = current(array_filter($manyDropped['diagnostics'], static fn(array $diagnostic): bool => 'artifact_inputs_rejected' === ($diagnostic['code'] ?? null)));
$manyDroppedContext = $manyDroppedSummary['context'] ?? array();
$assert(12 === ($manyDroppedContext['rejected_count'] ?? null) && 12 === ($manyDroppedContext['rejected_by_code']['file_limit_exceeded'] ?? null) && 10 === count($manyDroppedContext['samples'] ?? array()) && 2 === ($manyDroppedContext['samples_omitted'] ?? null), 'Many rejected inputs retain complete counts with bounded samples.');

$adversarialFiles = array(array('path' => 'index.html', 'content' => '<main>Accepted</main>'));
for ($index = 0; $index < 12; ++$index) {
    $adversarialFiles[] = array(
        'path' => 'assets/' . str_repeat('p', 1024) . '-' . $index . '.json',
        'content' => '{}',
        'role' => str_repeat('untrusted-role-', 128),
        'type' => str_repeat('untrusted-type-', 128),
    );
}
$adversarialArtifact = array('entrypoint' => 'index.html', 'compiler_limits' => array('max_files' => 1), 'files' => $adversarialFiles);
$aggregate = static function (array $result): array {
    foreach ($result['diagnostics'] as $diagnostic) {
        if ('artifact_inputs_rejected' === ($diagnostic['code'] ?? null)) return $diagnostic['context'];
    }
    return array();
};
$direct = (new ArtifactCompiler())->compile($adversarialArtifact)->toArray();
$directAggregate = $aggregate($direct);
$assert(12 === ($directAggregate['rejected_count'] ?? null) && array('file_limit_exceeded' => 12) === ($directAggregate['rejected_by_code'] ?? null) && 10 === count($directAggregate['samples'] ?? array()) && 2 === ($directAggregate['samples_omitted'] ?? null), 'Direct final diagnostics preserve exact aggregate counts while bounding samples.');
$assert(array('rejected_count', 'rejected_by_code', 'samples', 'samples_omitted') === array_keys($directAggregate) && !array_filter($directAggregate['samples'], static fn(array $sample): bool => strlen((string) ($sample['path'] ?? '')) > 256 || isset($sample['declared_role']) || isset($sample['declared_type'])), 'Direct final samples retain only capped safe paths and no arbitrary caller metadata.');
$assert(!array_filter($direct['diagnostics'], static fn(array $diagnostic): bool => in_array($diagnostic['code'] ?? '', array('file_limit_exceeded', 'unsafe_artifact_path', 'invalid_payload_reference', 'invalid_base64_content', 'missing_file_payload', 'artifact_file_too_large', 'artifact_total_too_large'), true)), 'Direct final diagnostics do not forward detailed per-rejected warnings.');

$stagedCompiler = new ArtifactCompiler();
$stagedShared = $stagedCompiler->prepareShared($adversarialArtifact);
$stagedPages = $stagedCompiler->preparePages($adversarialArtifact, $stagedShared);
$stagedReceipts = $stagedCompiler->compilePreparedPages($stagedShared, $stagedPages);
$staged = $stagedCompiler->compose($stagedShared, $stagedReceipts)->toArray();
$assert($directAggregate === $aggregate($staged), 'Direct and staged final results expose the same exact bounded rejection aggregate.');
$assert(!array_filter($staged['diagnostics'], static fn(array $diagnostic): bool => in_array($diagnostic['code'] ?? '', array('file_limit_exceeded', 'unsafe_artifact_path', 'invalid_payload_reference', 'invalid_base64_content', 'missing_file_payload', 'artifact_file_too_large', 'artifact_total_too_large'), true)), 'Staged final diagnostics do not forward detailed per-rejected warnings.');

echo "artifact normalizer source budget: ok\n";

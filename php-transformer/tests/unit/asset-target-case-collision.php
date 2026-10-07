<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$failure = static fn(array $result): string => implode(' | ', array_map(static fn(array $diagnostic): string => (string) ($diagnostic['message'] ?? ''), $result['diagnostics'] ?? array()));

// Two captured media files whose paths differ only by letter case. A
// case-sensitive capture (Linux) holds both; a case-insensitive theme
// filesystem (macOS, Windows) would write them to one file. The site plan
// contract rejects targets that differ only by case, so the compiler must
// give each spelling its own target.
$upperPng = base64_encode("\x89PNG\r\n\x1a\n" . 'upper');
$lowerPng = base64_encode("\x89PNG\r\n\x1a\n" . 'lower');
$html = '<!doctype html><html><body><main><h1>Home</h1><p>Two pictures.</p><img src="media/Photo-640w.png" alt="Upper"><img src="media/photo-640w.png" alt="Lower"></main></body></html>';
$image = static fn(string $path, string $png): array => array('path' => $path, 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => $png);
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();

$files = array(
    array('path' => 'index.html', 'content' => $html),
    $image('media/Photo-640w.png', $upperPng),
    $image('media/photo-640w.png', $lowerPng),
);
$result = $compile($files);
$assert(in_array($result['status'] ?? '', array('success', 'success_with_warnings'), true), 'Two files whose paths differ only by case compile: ' . $failure($result));
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assets = array_column($plan['assets'], null, 'source_path');
$upper = $assets['media/Photo-640w.png'] ?? null;
$lower = $assets['media/photo-640w.png'] ?? null;
$assert(is_array($upper) && is_array($lower), 'Both spellings stay separate assets under their own source paths.');
$assert('assets/media/Photo-640w.png' === $upper['target_path'], 'The byte-order-first spelling keeps its target: ' . $upper['target_path']);
$assert('assets/media/photo-640w-2.png' === $lower['target_path'], 'The later spelling gets a deterministic numbered target: ' . $lower['target_path']);
$assert(strtolower($upper['target_path']) !== strtolower($lower['target_path']), 'The two targets differ beyond letter case.');
$assert($upperPng === ($upper['content_base64'] ?? null) && $lowerPng === ($lower['content_base64'] ?? null), 'Each target carries its own bytes.');
$assert($upper['token'] !== $lower['token'], 'Each target has its own token.');
$tokens = array_column($plan['reference_tokens'], 'target_path', 'source_path');
$assert(($tokens['media/Photo-640w.png'] ?? null) === $upper['target_path'] && ($tokens['media/photo-640w.png'] ?? null) === $lower['target_path'], 'Reference tokens follow the renamed target.');
$markup = implode("\n", array_map(static fn(array $page): string => (string) ($page['canonical_block_markup'] ?? ''), $plan['pages']));
$assert(str_contains($markup, $upper['token']) && str_contains($markup, $lower['token']), 'Each image reference resolves to its own asset token.');
$writes = array_column($plan['writes'] ?? array(), null, 'target_path');
$assert(isset($writes['assets/media/Photo-640w.png'], $writes['assets/media/photo-640w-2.png']), 'Both files are written under distinct theme paths.');

// The surviving spelling does not depend on input order.
$reversed = $compile(array($files[0], $files[2], $files[1]));
$assert(in_array($reversed['status'] ?? '', array('success', 'success_with_warnings'), true), 'Reversed file order compiles: ' . $failure($reversed));
$reversedAssets = array_column($reversed['source_reports']['wordpress_site_plan']['assets'], 'target_path', 'source_path');
$assert('assets/media/Photo-640w.png' === $reversedAssets['media/Photo-640w.png'] && 'assets/media/photo-640w-2.png' === $reversedAssets['media/photo-640w.png'], 'Target spelling is independent of file order.');

// Identical bytes under two spellings are still two addressable targets.
$sameBytes = $compile(array($files[0], $image('media/Photo-640w.png', $upperPng), $image('media/photo-640w.png', $upperPng)));
$assert(in_array($sameBytes['status'] ?? '', array('success', 'success_with_warnings'), true), 'Identical bytes under case-variant paths compile: ' . $failure($sameBytes));
$sameBytesTargets = array_column($sameBytes['source_reports']['wordpress_site_plan']['assets'], 'target_path', 'source_path');
$assert('assets/media/photo-640w-2.png' === ($sameBytesTargets['media/photo-640w.png'] ?? null), 'Identical bytes still get a distinct target.');

// The numbered target skips names other files already use, in any case.
$crowded = $compile(array(
    array('path' => 'index.html', 'content' => '<!doctype html><html><body><main><h1>Home</h1><img src="media/Photo-640w.png" alt="A"><img src="media/photo-640w.png" alt="B"><img src="media/PHOTO-640w.png" alt="C"><img src="media/Photo-640w-2.png" alt="D"></main></body></html>'),
    $image('media/Photo-640w.png', $upperPng),
    $image('media/photo-640w.png', $lowerPng),
    $image('media/PHOTO-640w.png', base64_encode("\x89PNG\r\n\x1a\n" . 'caps')),
    $image('media/Photo-640w-2.png', base64_encode("\x89PNG\r\n\x1a\n" . 'real-2')),
));
$assert(in_array($crowded['status'] ?? '', array('success', 'success_with_warnings'), true), 'Three spellings beside a real -2 file compile: ' . $failure($crowded));
$crowdedTargets = array_column($crowded['source_reports']['wordpress_site_plan']['assets'], 'target_path', 'source_path');
$assert('assets/media/PHOTO-640w.png' === $crowdedTargets['media/PHOTO-640w.png'], 'Uppercase sorts first and keeps its spelling: ' . $crowdedTargets['media/PHOTO-640w.png']);
$assert('assets/media/Photo-640w-2.png' === $crowdedTargets['media/Photo-640w-2.png'], 'A real file keeps its own name: ' . $crowdedTargets['media/Photo-640w-2.png']);
$assert('assets/media/Photo-640w-3.png' === $crowdedTargets['media/Photo-640w.png'], 'The next spelling skips the taken -2 name: ' . $crowdedTargets['media/Photo-640w.png']);
$assert('assets/media/photo-640w-4.png' === $crowdedTargets['media/photo-640w.png'], 'The last spelling takes the next free number: ' . $crowdedTargets['media/photo-640w.png']);
$lowered = array_map('strtolower', array_values($crowdedTargets));
$assert(count($lowered) === count(array_unique($lowered)), 'No two targets collide case-insensitively.');

// Files whose paths already differ beyond case are untouched.
$distinct = $compile(array($files[0], $image('media/Photo-640w.png', $upperPng), $image('media/photo-320w.png', $lowerPng)));
$distinctTargets = array_column($distinct['source_reports']['wordpress_site_plan']['assets'], 'target_path', 'source_path');
$assert('assets/media/Photo-640w.png' === $distinctTargets['media/Photo-640w.png'] && 'assets/media/photo-320w.png' === $distinctTargets['media/photo-320w.png'], 'Distinct paths keep their spelling.');

echo "asset target case collision: ok\n";

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$failure = static fn(array $result): string => implode(' | ', array_map(static fn(array $diagnostic): string => (string) ($diagnostic['message'] ?? ''), $result['diagnostics'] ?? array()));
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$image = static fn(string $path, string $bytes): array => array('path' => $path, 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n" . $bytes));
$hasDotRun = static fn(string $path): bool => str_contains($path, '..');

// Bundlers emit real filenames such as `_astro/_..BD0pHwwn.css`. Hosts that
// concatenate theme stylesheets (WordPress.com `_static/??`) reject any path
// containing `..`, so the theme would render unstyled. Every emitted target
// must keep `..` out of its segments, and every reference must follow it.
$html = '<!doctype html><html><head><link rel="stylesheet" href="_astro/_..BD0pHwwn.css"></head><body><main><h1 class="probe">Home</h1><img src="media/a...b.png" alt="Probe"></main></body></html>';
$result = $compile(array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => '_astro/_..BD0pHwwn.css', 'content' => '.probe{color:#c00}.bg{background:url(../media/a...b.png)}'),
    $image('media/a...b.png', 'dots'),
));
$assert(in_array($result['status'] ?? '', array('success', 'success_with_warnings'), true), 'Dotted asset filenames compile: ' . $failure($result));
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$assets = array_column($plan['assets'], null, 'source_path');
$stylesheet = $assets['_astro/_..BD0pHwwn.css'] ?? null;
$picture = $assets['media/a...b.png'] ?? null;
$assert(is_array($stylesheet) && is_array($picture), 'Dotted files stay assets under their captured source paths.');
$assert('assets/_astro/_.BD0pHwwn.css' === $stylesheet['target_path'], 'The stylesheet target collapses the dot run: ' . $stylesheet['target_path']);
$assert('assets/media/a.b.png' === $picture['target_path'], 'The image target collapses the dot run: ' . $picture['target_path']);

// No target, write path or reference token carries `..`.
$writes = array_column($plan['writes'] ?? array(), null, 'target_path');
foreach ( array_merge(array_column($plan['assets'], 'target_path'), array_keys($writes), array_column($plan['reference_tokens'], 'target_path')) as $target ) {
    $assert(!$hasDotRun((string) $target), 'No emitted target contains `..`: ' . $target);
}

// References resolve by source path and follow the renamed target.
$tokens = array_column($plan['reference_tokens'], 'target_path', 'source_path');
$assert('assets/_astro/_.BD0pHwwn.css' === ($tokens['_astro/_..BD0pHwwn.css'] ?? null), 'The stylesheet reference token maps to the new target.');
$assert('assets/media/a.b.png' === ($tokens['media/a...b.png'] ?? null), 'The image reference token maps to the new target.');
$markup = implode("\n", array_map(static fn(array $page): string => (string) ($page['canonical_block_markup'] ?? ''), $plan['pages']));
$assert(str_contains($markup, (string) $picture['token']), 'The page image resolves to the renamed asset token.');
$bootstrap = (string) ($writes['functions.php']['payload']['data'] ?? '');
$assert(str_contains($bootstrap, "get_theme_file_uri( 'assets/_astro/_.BD0pHwwn.css' )"), 'The stylesheet is enqueued from its renamed target.');
$assert(!str_contains($bootstrap, '_..BD0pHwwn'), 'The bootstrap never names the dotted filename.');

// The stylesheet content survives under the new name.
$css = (string) ($writes['assets/_astro/_.BD0pHwwn.css']['payload']['data'] ?? '');
$assert(str_contains($css, '.probe{') && str_contains($css, '#c00'), 'The stylesheet rule survives: ' . $css);
$assert(str_contains($css, 'url({{wordpress-site-plan:asset:' . $picture['token'] . '}})'), 'The stylesheet url() follows the renamed image: ' . $css);

// A file that already uses the collapsed name keeps it; the dotted spelling
// gets the next numbered target, independent of file order.
$collisionFiles = array(
    array('path' => 'index.html', 'content' => '<!doctype html><html><body><main><h1>Home</h1><img src="media/a.b.png" alt="Plain"><img src="media/a...b.png" alt="Dotted"></main></body></html>'),
    $image('media/a...b.png', 'dotted'),
    $image('media/a.b.png', 'plain'),
);
foreach ( array($collisionFiles, array($collisionFiles[0], $collisionFiles[2], $collisionFiles[1])) as $files ) {
    $collision = $compile($files);
    $assert(in_array($collision['status'] ?? '', array('success', 'success_with_warnings'), true), 'A dotted file beside its collapsed name compiles: ' . $failure($collision));
    WordPressSitePlan::assertValid($collision['source_reports']['wordpress_site_plan']);
    $collisionTargets = array_column($collision['source_reports']['wordpress_site_plan']['assets'], 'target_path', 'source_path');
    $assert('assets/media/a.b.png' === ($collisionTargets['media/a.b.png'] ?? null), 'The existing file keeps its name: ' . ($collisionTargets['media/a.b.png'] ?? ''));
    $assert('assets/media/a.b-2.png' === ($collisionTargets['media/a...b.png'] ?? null), 'The dotted file gets a numbered target: ' . ($collisionTargets['media/a...b.png'] ?? ''));
}

echo "asset target dot segments: ok\n";

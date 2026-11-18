<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
};

// A font stylesheet (the shape a Google Fonts css2 response takes) holds only
// @font-face rules. theme.json registers every face, so projection leaves the
// sheet with no bytes: writing and enqueueing it would be a wasted request.
$files = array(
    'index.html' => '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="assets/fonts.css"><link rel="stylesheet" href="assets/site.css"></head><body><main id="content"><h1>Home</h1><p>Hello</p></main></body></html>',
    'assets/fonts.css' => '@font-face{font-family:Test;font-style:normal;font-weight:400;src:url("font.woff2") format("woff2")}',
    'assets/site.css' => 'body{font-family:Test,sans-serif}#content{background:#111}',
    'assets/font.woff2' => "wOF2\x00\x01\x00\x00",
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(array() !== $plan, 'The site plan compiles.');

$writes = array_column($plan['writes'], null, 'target_path');
$theme = json_decode((string) ($writes['theme.json']['payload']['data'] ?? '{}'), true);
$faces = array_merge(...array_map(static fn (array $family): array => $family['fontFace'] ?? array(), $theme['settings']['typography']['fontFamilies'] ?? array()));
$assert(in_array('file:./assets/assets/font.woff2', array_column($faces, 'src'), true), 'theme.json registers the font stylesheet\'s face.');

$assert(0 === ($plan['quality']['metrics']['empty_asset_count'] ?? null), 'The site plan reports empty_asset_count 0.');
$assert(array() === array_filter($plan['assets'], static fn (array $asset): bool => 'assets/fonts.css' === ($asset['source_path'] ?? null)), 'The emptied font stylesheet is not a plan asset.');
$assert(!isset($writes['assets/assets/fonts.css']), 'The emptied font stylesheet is not written.');
$assert(array() === array_filter($writes, static fn (array $write): bool => str_ends_with($write['target_path'], '.css') && '' === trim((string) ($write['payload']['data'] ?? 'x'))), 'No zero-byte stylesheet is written.');

$tokens = array_fill_keys(array_column($plan['reference_tokens'], 'token'), true);
foreach ($plan['pages'] as $page) foreach ($page['document_metadata']['links'] ?? array() as $order => $link) {
    $assert($order === $link['order'], 'Head link declarations stay densely ordered.');
    $assert(preg_match('/asset:([^}]+)\}\}/', (string) ($link['asset_reference'] ?? ''), $match) && isset($tokens[$match[1]]), 'Every head link declaration names a declared asset.');
}
$delivered = array_column(ThemeBootstrap::documentStylesheets($plan, 'index.html'), 'target_path');
$assert(in_array('assets/assets/site.css', $delivered, true) && !in_array('assets/assets/fonts.css', $delivered, true), 'The page still enqueues its author sheet and not the emptied one.');
$assert(!str_contains((string) ($writes['functions.php']['payload']['data'] ?? ''), 'fonts.css'), 'The bootstrap does not reference the emptied sheet.');

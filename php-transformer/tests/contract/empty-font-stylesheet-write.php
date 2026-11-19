<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
};

// An external font stylesheet (the shape a fetched Google Fonts css2 response
// takes) holds only @font-face rules, and theme.json registers every face.
// The `font-stretch` descriptor keeps theme.json from restating the rule
// exactly, so the projection pass leaves the rule in the row. Writing the
// sheet then drops the face, whose site-root source the sheet cannot resolve,
// so the bytes written are empty. Emptiness is judged on those written bytes:
// the sheet is neither written nor linked, and the counter counts empty writes.
$sheet = 'external/8e2e7efb6cf0362d/css2-00177dc3aad1.css';
$files = array(
    array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="' . $sheet . '"><link rel="stylesheet" href="assets/site.css"></head><body><main id="content"><h1>Home</h1><p>Hello</p></main></body></html>'),
    array('path' => $sheet, 'content' => "/* latin */\n@font-face {\n  font-family: 'Test';\n  font-style: normal;\n  font-weight: 400;\n  font-stretch: 100%;\n  font-display: swap;\n  src: url(/assets/fonts/test.woff2) format('woff2');\n}\n"),
    array('path' => 'assets/site.css', 'content' => 'body{font-family:Test,sans-serif}#content{background:#111}'),
    array('path' => 'fonts/test.woff2', 'content_base64' => base64_encode("wOF2\x00\x01\x00\x00")),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(array() !== $plan, 'The site plan compiles.');
WordPressSitePlan::assertValid($plan);

$writes = array_column($plan['writes'], null, 'target_path');
$theme = json_decode((string) ($writes['theme.json']['payload']['data'] ?? '{}'), true);
$faces = array_merge(...array_map(static fn (array $family): array => $family['fontFace'] ?? array(), $theme['settings']['typography']['fontFamilies'] ?? array()));
$assert(array('Test') === array_values(array_unique(array_column($faces, 'family'))), 'theme.json registers the font stylesheet\'s only face.');

$target = 'assets/' . $sheet;
$assert(!isset($writes[$target]), 'No write targets the emptied font stylesheet.');
$assert(array() === array_filter($plan['writes'], static fn (array $write): bool => 'theme_asset' === $write['kind'] && str_ends_with($write['target_path'], '.css') && '' === trim((string) ($write['payload']['data'] ?? 'x'))), 'No zero-byte stylesheet is written.');
$assert(0 === ($plan['quality']['metrics']['empty_asset_count'] ?? null), 'The site plan reports empty_asset_count 0.');
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
    $write = $writes[$asset['target_path']] ?? null;
    $assert(is_array($write) && '' !== trim((string) ($write['payload']['data'] ?? '')), 'Every stylesheet row has a non-empty write: ' . $asset['target_path']);
}

$tokens = array_fill_keys(array_column($plan['reference_tokens'], 'token'), true);
$sheetToken = 'asset-' . substr(hash('sha256', $target), 0, 16);
$assert(!isset($tokens[$sheetToken]), 'The emptied font stylesheet has no reference token.');
foreach ($plan['pages'] as $page) foreach ($page['document_metadata']['links'] ?? array() as $order => $link) {
    $assert($order === $link['order'], 'Head link declarations stay densely ordered.');
    $assert(!str_contains((string) ($link['asset_reference'] ?? ''), $sheetToken), 'No document link names the emptied font stylesheet.');
    $assert(preg_match('/asset:([^}]+)\}\}/', (string) ($link['asset_reference'] ?? ''), $match) && isset($tokens[$match[1]]), 'Every head link declaration names a declared asset.');
}
$delivered = array_column(ThemeBootstrap::documentStylesheets($plan, 'index.html'), 'target_path');
$assert(in_array('assets/assets/site.css', $delivered, true) && !in_array($target, $delivered, true), 'The page still enqueues its author sheet and not the emptied one.');
$assert(!str_contains((string) ($writes['functions.php']['payload']['data'] ?? ''), 'css2-00177dc3aad1'), 'The bootstrap does not reference the emptied sheet.');

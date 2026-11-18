<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
};

// Use accepted box-tree evidence, not the legacy author-CSS route. Retirement
// must discard the external font sheet after theme.json absorbs every face.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<link data-blocks-engine-node-id="fonts" rel="stylesheet" href="assets/fonts.css"></head>', $html);
$tree['nodes']['n2']['children'][] = 'fonts';
$tree['nodes']['fonts'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$html = str_replace('</head>', '<style>body{font-family:Arial}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$files = array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree),
    'assets/fonts.css' => '@font-face{font-family:Arial;font-style:normal;font-weight:400;src:url("font.woff2") format("woff2")}',
    'assets/font.woff2' => "wOF2\x00\x01\x00\x00",
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(array() !== $plan, 'The site plan compiles.');
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The page uses the box-tree evidence route.');

$writes = array_column($plan['writes'], null, 'target_path');
$theme = json_decode((string) ($writes['theme.json']['payload']['data'] ?? '{}'), true);
$faces = array_merge(...array_map(static fn (array $family): array => $family['fontFace'] ?? array(), $theme['settings']['typography']['fontFamilies'] ?? array()));
$assert(in_array('file:./assets/assets/font.woff2', array_column($faces, 'src'), true), 'theme.json registers the font stylesheet\'s face.');

$assert(0 === ($plan['quality']['metrics']['empty_asset_count'] ?? null), 'The site plan reports empty_asset_count 0.');
$assert(array() === array_filter($plan['assets'], static fn (array $asset): bool => 'assets/fonts.css' === ($asset['source_path'] ?? null)), 'The emptied font stylesheet is not a plan asset.');
$assert(!isset($writes['assets/assets/fonts.css']), 'The emptied font stylesheet is not written.');
$assert(array() === array_filter($writes, static fn (array $write): bool => str_ends_with($write['target_path'], '.css') && '' === trim((string) ($write['payload']['data'] ?? 'x'))), 'No zero-byte stylesheet is written.');

// Check the decision itself, before the former late elision could hide a
// retained empty identity. This is the font sheet after theme.json projection.
$pages = $plan['pages'];
$pages[0]['document_metadata']['links'] = array(array('asset_reference' => '{{wordpress-site-plan:asset:retired-font}}', 'order' => 0));
$retired = EvidenceRouteStylesheets::retire(array(array(
    'kind' => 'css', 'source' => 'author', 'token' => 'retired-font',
    'content' => '', 'scopes' => array(array('kind' => 'page', 'source_path' => 'index.html')),
)), $pages);
$assert(array() === $retired, 'Retirement itself drops the absorbed font sheet.');
$assert(array() === $pages[0]['document_metadata']['links'], 'Retirement itself drops the font sheet head link.');

$tokens = array_fill_keys(array_column($plan['reference_tokens'], 'token'), true);
$assert(!str_contains((string) json_encode(array_column($plan['pages'], 'document_metadata')), 'fonts.css'), 'No page head link names the retired font path.');
foreach ($plan['pages'] as $page) foreach ($page['document_metadata']['links'] ?? array() as $order => $link) {
    $assert($order === $link['order'], 'Head link declarations stay densely ordered.');
    $assert(preg_match('/asset:([^}]+)\}\}/', (string) ($link['asset_reference'] ?? ''), $match) && isset($tokens[$match[1]]), 'Every head link declaration names a declared asset.');
}
$delivered = array_column(ThemeBootstrap::documentStylesheets($plan, 'index.html'), 'target_path');
$assert(!in_array('assets/assets/fonts.css', $delivered, true), 'The evidence route does not enqueue the emptied font sheet.');
$assert(!str_contains((string) ($writes['functions.php']['payload']['data'] ?? ''), 'fonts.css'), 'The bootstrap does not reference the emptied sheet.');

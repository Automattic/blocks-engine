<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// One inline stylesheet appears under a different media on each page. Chrome
// is lifted once per media, and each lifted asset needs its own source identity.
$document = static fn(string $title, string $media): string => '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title>'
    . '<style media="' . $media . '">.site-header a{color:#fc0}</style></head><body>'
    . '<header class="site-header"><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
    . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('Home', 'screen'),
        'about.html' => $document('About', 'all'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

// Materialization builds this from the plan and rejects shared source identities.
new AssetReferenceCanonicalizer(array_map(static fn(array $asset): array => array('source_path' => (string) $asset['source_path'], 'token' => (string) $asset['token']), $plan['assets']));
$sources = array_column($plan['assets'], 'source_path');
$assert(count($sources) === count(array_unique($sources)), 'Every planned asset has a unique source identity.');
$shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains((string) $asset['target_path'], '/shared-chrome-') && str_starts_with((string) ($asset['content'] ?? ''), '.site-header a{')));
$assert(2 === count($shared), 'Each media condition keeps its own shared-chrome asset.');
$assert(array('screen', 'all') === array_column($shared, 'media'), 'Shared-chrome assets keep their media conditions.');
$assert(count($shared) === count(array_unique(array_column($shared, 'target_path'))), 'Shared-chrome assets keep distinct targets.');
foreach ($shared as $asset) $assert(str_ends_with((string) $asset['source_path'], '.shared-chrome'), 'Shared-chrome source identities keep their suffix.');

fwrite(STDOUT, "Shared-chrome inline media source identity test passed.\n");

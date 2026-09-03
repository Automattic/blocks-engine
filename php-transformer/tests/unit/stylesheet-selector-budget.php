<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetChunker;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$css = '@import url("fonts.css");.one,.two,.three{color:red}@media (max-width:600px){.one,.two,.three{color:blue}}';
$chunks = (new CssStylesheetChunker())->chunk($css, 2);
$assert(
    array(
        '@import url("fonts.css");.one,.two{color:red}',
        '.three{color:red}',
        '@media (max-width:600px){.one,.two{color:blue}}',
        '@media (max-width:600px){.three{color:blue}}',
    ) === $chunks,
    'selector-budget chunking preserves preambles, selector order, and nested media conditions'
);

$result = (new ArtifactCompiler(stylesheetSelectorBudget: 2))->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><p class="one">One</p><p class="two">Two</p><p class="three">Three</p></main>'),
        array('path' => 'website/assets/site.css', 'kind' => 'css', 'content' => '.one,.two,.three{color:red}@media (max-width:600px){.one,.two,.three{color:blue}}'),
    ),
))->toArray();
$assets = array_values(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_starts_with((string) ($asset['path'] ?? ''), 'website/assets/site')));
$assetsByPath = array_column($assets, null, 'path');
$expectedPaths = array('website/assets/site.css', 'website/assets/site.chunk-2.css', 'website/assets/site.chunk-3.css', 'website/assets/site.chunk-4.css');
$assert(
    '.one,.two{color:red}' === ($assetsByPath['website/assets/site.css']['content'] ?? null)
        && '.three{color:red}' === ($assetsByPath['website/assets/site.chunk-2.css']['content'] ?? null)
        && '@media (max-width:600px){.one,.two{color:blue}}' === ($assetsByPath['website/assets/site.chunk-3.css']['content'] ?? null)
        && '@media (max-width:600px){.three{color:blue}}' === ($assetsByPath['website/assets/site.chunk-4.css']['content'] ?? null),
    'projected stylesheet chunks retain ordered cascade fragments as distinct assets'
);
$planAssets = array_values(array_filter($result['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn (array $asset): bool => str_starts_with((string) ($asset['source_path'] ?? ''), 'website/assets/site')));
$planAssetPathsByToken = array();
foreach ($planAssets as $asset) {
    $planAssetPathsByToken['{{wordpress-site-plan:asset:' . ($asset['token'] ?? '') . '}}'] = (string) ($asset['source_path'] ?? '');
}
$linkPaths = array_map(static fn (array $link): string => (string) ($planAssetPathsByToken[$link['asset_reference'] ?? ''] ?? ''), $result['source_reports']['wordpress_site_plan']['pages'][0]['document_metadata']['links'] ?? array());
$assert(
    $expectedPaths === $linkPaths,
    'stylesheet chunk links are wired into the WordPress site plan in source order'
);

if (0 < $failures) {
    exit(1);
}
fwrite(STDOUT, "stylesheet selector budget passed\n");

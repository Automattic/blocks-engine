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

$css = '@charset "UTF-8";@import url("fonts.css");@namespace svg url("http://www.w3.org/2000/svg");svg|a,svg|b,svg|c{color:red}@media (max-width:600px){svg|a,svg|b,svg|c{color:blue}}';
$chunks = (new CssStylesheetChunker())->chunk($css, 2);
$assert(
    array(
        '@charset "UTF-8";@import url("fonts.css");@namespace svg url("http://www.w3.org/2000/svg");svg|a,svg|b{color:red}',
        'svg|c{color:red}',
        '@media (max-width:600px){svg|a,svg|b{color:blue}}',
        '@media (max-width:600px){svg|c{color:blue}}',
    ) === $chunks,
    'selector-budget chunking preserves preambles, selector order, and nested media conditions'
);
$assert(
    '@charset "UTF-8";@namespace svg url("http://www.w3.org/2000/svg");' === (new CssStylesheetChunker())->continuationPreamble($css),
    'continuation stylesheets retain their required charset and namespace preamble without repeating imports'
);

$result = (new ArtifactCompiler(stylesheetSelectorBudget: 2))->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="assets/main.css"><main><p class="one">One</p><p class="two">Two</p><p class="three">Three</p></main>'),
        array('path' => 'website/assets/main.css', 'kind' => 'css', 'content' => '@import url("imported.css");.root{color:black}'),
        array('path' => 'website/assets/imported.css', 'kind' => 'css', 'content' => '.one,.two,.three{color:red}'),
    ),
))->toArray();
$assets = array_values(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_starts_with((string) ($asset['path'] ?? ''), 'website/assets/')));
$assetsByPath = array_column($assets, null, 'path');
$assert(
    str_contains((string) ($assetsByPath['website/assets/main.css']['content'] ?? ''), '@import url("imported.css");')
        && '@import url("imported.chunk-1.css");@import url("imported.chunk-2.css");' === ($assetsByPath['website/assets/imported.css']['content'] ?? null)
        && '.one,.two{color:red}' === ($assetsByPath['website/assets/imported.chunk-1.css']['content'] ?? null)
        && '.three{color:red}' === ($assetsByPath['website/assets/imported.chunk-2.css']['content'] ?? null),
    'imported stylesheets load every continuation chunk in original cascade order'
);
$planAssets = array_values(array_filter($result['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn (array $asset): bool => str_starts_with((string) ($asset['source_path'] ?? ''), 'website/assets/')));
$planAssetPathsByToken = array();
foreach ($planAssets as $asset) {
    $planAssetPathsByToken['{{wordpress-site-plan:asset:' . ($asset['token'] ?? '') . '}}'] = (string) ($asset['source_path'] ?? '');
}
$linkPaths = array_map(static fn (array $link): string => (string) ($planAssetPathsByToken[$link['asset_reference'] ?? ''] ?? ''), $result['source_reports']['wordpress_site_plan']['pages'][0]['document_metadata']['links'] ?? array());
$assert(
    array('website/assets/main.css') === $linkPaths && 4 === count($planAssets),
    'site-plan loading retains the source link while imported continuation assets remain packaged'
);

if (0 < $failures) {
    exit(1);
}
fwrite(STDOUT, "stylesheet selector budget passed\n");

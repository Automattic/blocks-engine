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
foreach ($assets as $asset) {
    $content = (string) ($asset['content'] ?? '');
    $assert(strlen($content) === ($asset['bytes'] ?? null) && hash('sha256', $content) === ($asset['provenance']['hash'] ?? null), 'chunk metadata identifies the exact emitted content, including continuation preambles');
}

// A directly linked stylesheet that is chunked keeps loading through its
// @import loader alone. Enqueuing the chunks as separate theme stylesheets
// loads them out of source order and a later chunk's rules lose to an earlier
// chunk's rules (the cascade inverts).
$page = static fn (string $title): string => '<link rel="stylesheet" href="/assets/site.css"><main><h1>' . $title . '</h1><p class="a">x</p></main>';
$result = (new ArtifactCompiler(stylesheetSelectorBudget: 2))->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'kind' => 'html', 'content' => $page('Home')),
        array('path' => 'website/news/index.html', 'kind' => 'html', 'content' => $page('News')),
        array('path' => 'website/assets/site.css', 'kind' => 'css', 'content' => 'h1{font-size:10px}.a,.b,.c{color:red}h1{font-size:66px}'),
    ),
))->toArray();
$bootstrap = '';
foreach ($result['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) {
        $bootstrap = (string) ($write['payload']['data'] ?? '');
    }
}
preg_match_all("/wp_enqueue_style\\( '[^']+', get_theme_file_uri\\( '([^']+)' \\)/", $bootstrap, $enqueued);
$assert(
    array('assets/website/assets/site.css', 'assets/website/assets/site.css') === array_values(array_filter($enqueued[1], static fn (string $path): bool => str_starts_with($path, 'assets/website/'))),
    'chunked stylesheets load only through their source-ordered loader, never as separately enqueued chunks'
);

if (0 < $failures) {
    exit(1);
}
fwrite(STDOUT, "stylesheet selector budget passed\n");

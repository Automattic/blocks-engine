<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$fallbacks = array(
    array('diagnostic_code' => 'html_script_fallback', 'selector' => '#widget', 'source_path' => 'index.html', 'conversion_classification' => 'runtime_gap', 'preservation_strategy' => 'core_html'),
    array('diagnostic_code' => 'html_unsafe_inline_svg', 'selector' => '.logo svg', 'source_path' => 'about.html', 'conversion_classification' => 'asset_gap', 'preservation_strategy' => 'materialize_asset'),
);
$sharedIsland = array('selector' => '#widget', 'source_path' => 'index.html', 'tag' => 'div', 'preservation_strategy' => 'scoped_runtime_metadata');
$secondIsland = array('selector' => '.map', 'source_path' => 'about.html', 'tag' => 'section', 'disposition' => 'preserved');
$report = ConversionReportProjection::fromResultParts('html', array(), $fallbacks, array(
    'html' => array(
        'source_provenance' => array(array('selector' => 'main', 'source_path' => 'index.html', 'conversion_classification' => 'native', 'preservation_strategy' => 'native_block')),
        'runtime_islands' => array($sharedIsland, $secondIsland),
    ),
    'runtime_islands' => array($sharedIsland),
), array(), array(), array());

$assert(
    array($sharedIsland, $secondIsland) === ($report['runtime_islands'] ?? null),
    'runtime islands retain first-occurrence ordering while overlapping declarations are deduplicated'
);
$assert(
    array('main', '#widget', '.logo svg', '#widget', '.map') === array_column($report['selector_summary']['selectors'] ?? array(), 'selector'),
    'selector summary retains source, fallback, and deduplicated runtime-island order'
);
$assert(
    array('index.html', 'about.html') === ($report['selector_summary']['source_paths'] ?? null),
    'selector summary source paths retain their first occurrence order'
);
$assert(
    array('native' => 1, 'runtime_gap' => 1, 'asset_gap' => 1, 'runtime_island_preserved' => 2) === ($report['conversion_classification_summary']['by_classification'] ?? null),
    'classification summary counts each normalized fallback and runtime island exactly once'
);
$assert(
    array('native_block' => 1, 'core_html' => 1, 'materialize_asset' => 1, 'scoped_runtime_metadata' => 2) === ($report['conversion_classification_summary']['by_preservation_strategy'] ?? null),
    'preservation strategy summary preserves normalized row counts and omission behavior'
);
$assert(
    array('html_script_fallback', 'html_unsafe_inline_svg') === array_column($report['fallback_diagnostics'] ?? array(), 'diagnostic_code'),
    'fallback diagnostics retain normalized final rows in input order'
);

$blocks = array(
    2 => array('blockName' => 'core/image', 'attrs' => array('url' => 'https://example.test/image', 'src' => 'https://example.test/image.jpg', 'href' => 'https://example.test/image-link', 'poster' => 'https://example.test/image.mp4')),
    4 => 'malformed block',
    6 => array('blockName' => 'core/navigation-link', 'attrs' => array('label' => 'Top', 'url' => '/top', 'kind' => 'custom')),
    9 => array('blockName' => 'core/group', 'attrs' => array('src' => 'https://example.test/group.jpg'), 'innerBlocks' => array(
        1 => array('blockName' => 'core/navigation-link', 'attrs' => array('label' => 'Child', 'url' => '/child', 'kind' => 'post-type')),
        3 => false,
        7 => array('blockName' => 'core/navigation-link', 'attrs' => array('label' => 'Child', 'url' => '/child', 'kind' => 'post-type')),
        8 => array('blockName' => 'core/video', 'attrs' => array('poster' => 'https://example.test/video.jpg', 'href' => array('invalid'))),
    )),
);
$blockReport = ConversionReportProjection::fromResultParts('html', $blocks, array(), array(), array(), array(), array());
$expectedBlockAssets = array(
    array('source' => 'block_attribute', 'block_path' => 'blocks.2', 'block_name' => 'core/image', 'attribute' => 'url', 'url' => 'https://example.test/image'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.2', 'block_name' => 'core/image', 'attribute' => 'src', 'url' => 'https://example.test/image.jpg'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.2', 'block_name' => 'core/image', 'attribute' => 'href', 'url' => 'https://example.test/image-link'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.2', 'block_name' => 'core/image', 'attribute' => 'poster', 'url' => 'https://example.test/image.mp4'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.6', 'block_name' => 'core/navigation-link', 'attribute' => 'url', 'url' => '/top'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.9', 'block_name' => 'core/group', 'attribute' => 'src', 'url' => 'https://example.test/group.jpg'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.9.innerBlocks.1', 'block_name' => 'core/navigation-link', 'attribute' => 'url', 'url' => '/child'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.9.innerBlocks.7', 'block_name' => 'core/navigation-link', 'attribute' => 'url', 'url' => '/child'),
    array('source' => 'block_attribute', 'block_path' => 'blocks.9.innerBlocks.8', 'block_name' => 'core/video', 'attribute' => 'poster', 'url' => 'https://example.test/video.jpg'),
);
$expectedBlockNavigation = array(
    array('source' => 'block', 'block_path' => 'blocks.6', 'block_name' => 'core/navigation-link', 'label' => 'Top', 'url' => '/top', 'kind' => 'custom'),
    array('source' => 'block', 'block_path' => 'blocks.9.innerBlocks.1', 'block_name' => 'core/navigation-link', 'label' => 'Child', 'url' => '/child', 'kind' => 'post-type'),
    array('source' => 'block', 'block_path' => 'blocks.9.innerBlocks.7', 'block_name' => 'core/navigation-link', 'label' => 'Child', 'url' => '/child', 'kind' => 'post-type'),
);
$assert($expectedBlockAssets === ($blockReport['asset_refs'] ?? null), 'block assets retain depth-first sparse paths and attribute order');
$assert($expectedBlockNavigation === ($blockReport['navigation_candidates'] ?? null), 'block navigation retains depth-first sparse paths and malformed-entry skipping');

$artifactAsset = array('source_path' => 'index.html', 'selector' => 'img.hero', 'url' => '/hero.jpg');
$artifactLink = array('source_path' => 'index.html', 'selector' => 'a.home', 'url' => '/home', 'target_path' => 'home/index.html');
$artifactReport = ConversionReportProjection::fromResultParts('artifact', $blocks, array(), array(
    'artifact' => array(
        'asset_references' => array($artifactAsset, $artifactAsset),
        'internal_links' => array($artifactLink, $artifactLink),
    ),
), array(), array(), array());
$assert(array($artifactAsset) === ($artifactReport['asset_refs'] ?? null), 'nonempty artifact assets suppress block attribute collection and retain first-occurrence deduplication');
$assert(
    array_merge(array(array('source' => 'artifact_reference', 'source_path' => 'index.html', 'selector' => 'a.home', 'url' => '/home', 'target_path' => 'home/index.html')), $expectedBlockNavigation) === ($artifactReport['navigation_candidates'] ?? null),
    'artifact links precede block navigation candidates while duplicate artifact rows are deduplicated'
);

if ( 0 < $failures ) {
    exit(1);
}

echo "conversion-report-projection-input-reuse ok\n";

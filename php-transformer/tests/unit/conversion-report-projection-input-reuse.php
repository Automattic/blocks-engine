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

if ( 0 < $failures ) {
    exit(1);
}

echo "conversion-report-projection-input-reuse ok\n";

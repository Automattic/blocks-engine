<?php

declare(strict_types=1);

require_once __DIR__ . '/../../figma-transformer.php';
require_once __DIR__ . '/../../scripts/figma-fixture-selection.php';
require_once __DIR__ . '/ContractHelpers.php';
require_once __DIR__ . '/DiagnosticsEvidenceContract.php';
require_once __DIR__ . '/FixtureMatrixContract.php';
require_once __DIR__ . '/GeometryBoxContract.php';
require_once __DIR__ . '/LayoutMismatchContract.php';
require_once __DIR__ . '/NodeTraceContract.php';
require_once __DIR__ . '/OriginInferenceContract.php';
require_once __DIR__ . '/RenderStyleMismatchContract.php';
require_once __DIR__ . '/SyntheticFigKiwiFixtureBuilder.php';
require_once __DIR__ . '/VisualNodeMapContract.php';

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;
use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCommandDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiParser;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Parity\VisualAttributionReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphPagePlanner;

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$imageHash = '0123456789abcdef0123456789abcdef01234567';
$vectorCommandBlob = chr(1) . pack('g', 0.0) . pack('g', 0.0)
    . chr(2) . pack('g', 10.0) . pack('g', 0.0)
    . chr(2) . pack('g', 10.0) . pack('g', 10.0)
    . chr(0);
$quadraticCommandBlob = chr(0)
    . chr(1) . pack('g', 0.0) . pack('g', 0.0)
    . chr(3) . pack('g', 4.0) . pack('g', 8.0) . pack('g', 8.0) . pack('g', 0.0)
    . chr(0);

$scenegraph = array(
    'name'   => 'Fixture Site',
    'blobs'  => array(
        array('bytes' => $vectorCommandBlob),
    ),
    'assets' => array(
        'hero-image' => array(
            'name'      => 'Hero Image',
            'mime_type' => 'image/svg+xml',
            'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
        ),
        'remote-image' => array(
            'url' => 'https://cdn.example.com/remote.png',
        ),
        $imageHash => array(
            'name'      => 'Fixture Photo',
            'mime_type' => 'image/jpeg',
            'content'   => 'fixture image bytes',
        ),
    ),
    'nodes'  => array(
        array(
            'id'              => '1:1',
            'type'            => 'FRAME',
            'name'            => 'Hero section',
            'width'           => 1200,
            'height'          => 600,
            'backgroundColor' => '#ffffff',
            'layoutMode'      => 'VERTICAL',
            'primaryAxisAlignItems' => 'CENTER',
            'counterAxisAlignItems' => 'MIN',
            'paddingTop'      => 40,
            'paddingRight'    => 32,
            'paddingBottom'   => 40,
            'paddingLeft'     => 32,
            'itemSpacing'     => 24,
            'children'        => array(
                array(
                    'id'         => '1:2',
                    'type'       => 'TEXT',
                    'name'       => 'Hero title',
                    'text'       => 'Hello Figma',
                    'fontSize'   => 48,
                    'fontWeight' => 700,
                    'color'      => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3),
                ),
                array(
                    'id'         => '1:3',
                    'type'       => 'GROUP',
                    'name'       => 'Cards group',
                    'children'   => array(
                        array(
                            'id'       => '1:4',
                            'type'     => 'RECTANGLE',
                            'name'     => 'Hero image rectangle',
                            'absoluteRenderBounds' => array('width' => 320, 'height' => 180),
                            'x'        => 10,
                            'y'        => 20,
                            'layoutPositioning' => 'ABSOLUTE',
                            'fill'     => array('r' => 1, 'g' => 0, 'b' => 0),
                            'asset_id' => 'hero-image',
                        ),
                        array(
                            'id'         => '1:5',
                            'type'       => 'ROUNDED_RECTANGLE',
                            'name'       => 'Nested image paint',
                            'width'      => 160,
                            'height'     => 90,
                            'fillPaints' => array(
                                array(
                                    'type'  => 'IMAGE',
                                    'image' => array('hash' => hex2bin($imageHash), 'name' => 'fixture-photo-source'),
                                ),
                            ),
                        ),
                        array(
                            'id'           => '1:6',
                            'type'         => 'VECTOR',
                            'name'         => 'Blob vector',
                            'width'        => 10,
                            'height'       => 10,
                            'fillPaints'   => array(
                                array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)),
                            ),
                            'fillGeometry' => array(
                                array('commandsBlob' => 0, 'windingRule' => 'NONZERO'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array('id' => '1:2', 'type' => 'TEXT', 'name' => 'Duplicate title', 'text' => 'Duplicate'),
    ),
);

$result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph);
$sameResult = blocks_engine_figma_transformer_transform_scenegraph($scenegraph);

$fileContent = static function (array $result, string $path): string {
    return blocks_engine_figma_transformer_contract_file_content($result, $path);
};
$findVisualNode = static function (array $result, string $id): ?array {
    return blocks_engine_figma_transformer_contract_find_visual_node($result, $id);
};

$html = $fileContent($result, 'index.html');
$css = $fileContent($result, 'style.css');
$diagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $result['diagnostics'] ?? array()
);
$artifactQualitySignalCodes = static function (array $result): array {
    return blocks_engine_figma_transformer_contract_artifact_quality_signal_codes($result);
};
$artifactQualitySignal = static function (array $result, string $code): ?array {
    return blocks_engine_figma_transformer_contract_artifact_quality_signal($result, $code);
};

$assert('blocks-engine/figma-transformer/result/v1' === ($result['schema'] ?? null), 'result-schema');
$assert('success' === ($result['status'] ?? null), 'scenegraph-transform-success');
$assert(6 === ($result['metrics']['node_count'] ?? null), 'node-count');
$assert(2 === ($result['metrics']['asset_count'] ?? null), 'asset-count');
$assert(str_contains($html, 'Hello Figma'), 'html-contains-text');
$assert(str_contains($html, '<section class="figma-node-1-1-hero-section"'), 'frame-emits-section');
$assert(str_contains($html, '<h2 class="figma-node-1-2-hero-title"'), 'title-emits-heading');
$assert(str_contains($html, '<div class="figma-node-1-3-cards-group"'), 'group-emits-div');
$assert(! str_contains($html, '<FRAME') && ! str_contains($html, '<GROUP') && ! str_contains($html, '<TEXT') && ! str_contains($html, '<RECTANGLE'), 'html-avoids-custom-tags');
$assert(! str_contains($html, 'cdn.example.com') && ! str_contains($css, 'cdn.example.com'), 'html-css-avoid-external-cdn');
$assert(str_contains($css, '.figma-node-1-1-hero-section{width:100%;max-width:1200px;margin-left:auto;margin-right:auto;height:600px;background:#ffffff;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;padding-top:40px;padding-right:32px;padding-bottom:40px;padding-left:32px;gap:24px}'), 'css-frame-layout-style');
$assert(str_contains($css, '.figma-node-1-2-hero-title{font-size:48px;font-weight:700;color:#1a334d;flex-shrink:0}'), 'css-text-style');
$assert(str_contains($css, '.figma-node-1-4-hero-image-rectangle{width:320px;height:180px;position:absolute;left:10px;top:20px;background:#ff0000;background-image:url("assets/hero-image.svg")'), 'css-rectangle-asset-style');
$assert(str_contains($css, '.figma-node-1-5-nested-image-paint{') && str_contains($css, 'background-image:url("assets/fixture-photo.jpg")'), 'css-nested-image-hash-asset-style');
$assert('fixture image bytes' === $fileContent($result, 'assets/fixture-photo.jpg'), 'asset-content-preserved');

$missingEmissionResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Missing Emission Diagnostics Fixture',
    'nodes' => array(
        array(
            'id'       => 'missing:root',
            'type'     => 'FRAME',
            'name'     => 'Missing emission root',
            'width'    => 320,
            'height'   => 180,
            'children' => array(
                array('id' => 'missing:text', 'type' => 'TEXT', 'name' => 'Visible text', 'characters' => 'Synthetic visible copy', 'width' => 180, 'height' => 24),
                array('id' => 'missing:asset', 'type' => 'RECTANGLE', 'name' => 'Missing asset', 'width' => 80, 'height' => 60, 'asset_id' => 'missing-image'),
                array('id' => 'missing:vector', 'type' => 'VECTOR', 'name' => 'Missing vector geometry', 'width' => 20, 'height' => 20),
            ),
        ),
    ),
));
$missingEmissionHtml = $fileContent($missingEmissionResult, 'index.html');
$missingEmissionQualitySignalCodes = $artifactQualitySignalCodes($missingEmissionResult);
$missingAssetsSignal = $artifactQualitySignal($missingEmissionResult, 'missing_render_assets');
$vectorPlaceholderSignal = $artifactQualitySignal($missingEmissionResult, 'vector_placeholders');
$assert(str_contains($missingEmissionHtml, 'Synthetic visible copy'), 'missing-emission-text-still-emits');
$assert(in_array('missing_render_assets', $missingEmissionQualitySignalCodes, true), 'missing-emission-missing-asset-signal');
$assert(1 === ($missingAssetsSignal['count'] ?? null), 'missing-emission-missing-asset-count');
$assert(in_array('vector_placeholders', $missingEmissionQualitySignalCodes, true), 'missing-emission-vector-placeholder-signal');
$assert(1 === ($vectorPlaceholderSignal['count'] ?? null), 'missing-emission-vector-placeholder-count');

$cliOutputRoot = sys_get_temp_dir() . '/figma-transformer-cli-output-' . getmypid() . '-' . bin2hex(random_bytes(4));
$cliScenegraphPath = $cliOutputRoot . '/scenegraph.json';
$cliScenegraph = array(
    'name'   => 'CLI Output Fixture',
    'assets' => array(
        'cli-image' => array(
            'name'      => 'CLI Image',
            'mime_type' => 'image/svg+xml',
            'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"></svg>',
        ),
    ),
    'nodes'  => array(
        array(
            'id'       => 'cli:1',
            'type'     => 'RECTANGLE',
            'name'     => 'CLI Card',
            'width'    => 40,
            'height'   => 20,
            'asset_id' => 'cli-image',
        ),
    ),
);
mkdir($cliOutputRoot, 0777, true);
file_put_contents($cliScenegraphPath, json_encode($cliScenegraph, JSON_UNESCAPED_SLASHES));
$cliCommand = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(__DIR__ . '/../../bin/figma-transformer')
    . ' ' . escapeshellarg($cliScenegraphPath)
    . ' --output-dir=' . escapeshellarg($cliOutputRoot . '/artifact');
$cliJson = shell_exec($cliCommand);
$cliResult = is_string($cliJson) ? json_decode($cliJson, true) : null;
$assert(is_array($cliResult), 'cli-output-dir-json-result');
$assert('success' === ($cliResult['status'] ?? null), 'cli-output-dir-transform-success');
$assert($cliOutputRoot . '/artifact' === ($cliResult['output']['directory'] ?? null), 'cli-output-dir-report-directory');
$assert(! isset($cliResult['files'][0]['content']), 'cli-output-dir-omits-file-content-from-json');
$assert(is_file($cliOutputRoot . '/artifact/index.html'), 'cli-output-dir-writes-index');
$assert(is_file($cliOutputRoot . '/artifact/style.css'), 'cli-output-dir-writes-style');
$assert('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"></svg>' === file_get_contents($cliOutputRoot . '/artifact/assets/cli-image.svg'), 'cli-output-dir-preserves-asset-content');
$assert(str_contains((string) file_get_contents($cliOutputRoot . '/artifact/style.css'), 'background-image:url("assets/cli-image.svg")'), 'cli-output-dir-preserves-asset-reference');
$assert(str_contains($html, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"'), 'html-vector-blob-svg');
$assert(str_contains($html, 'd="M0 0L10 0 10 10Z"'), 'html-vector-blob-path');
$assert(str_contains($css, 'body{margin:0}'), 'css-static-page-body-shell');
$assert(str_contains($css, '.figma-root{position:relative;width:100%}'), 'css-static-page-root-shell');
$assert(! str_contains($css, 'width:max-content'), 'css-static-page-root-shell-not-fixed-canvas');
$assert(str_contains($css, '.figma-node-1-1-hero-section{width:100%;max-width:1200px;margin-left:auto;margin-right:auto;'), 'css-page-root-frame-is-centered-fluid');
$assert(! str_contains($css, 'overflow-x:hidden'), 'css-preserves-horizontal-scroll');
$assert(! str_contains($css, 'order:'), 'css-avoids-source-order');
$assert(! str_contains($css, 'font-family:Inter') && ! str_contains($css, 'body{margin:0;background') && ! str_contains($css, 'body{margin:0;color'), 'css-avoids-hardcoded-theme-style');

$absoluteTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name' => 'Absolute Transform Fixture',
    'nodes' => array(
        array(
            'id' => 'absolute:root',
            'type' => 'FRAME',
            'name' => 'Root',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 200),
            'children' => array(
                array(
                    'id' => 'absolute:child',
                    'type' => 'RECTANGLE',
                    'name' => 'Rotated child',
                    'absoluteBoundingBox' => array('x' => 40, 'y' => 50, 'width' => 80, 'height' => 60),
                    'layoutPositioning' => 'ABSOLUTE',
                    'relativeTransform' => array(
                        array(0, -1, 40),
                        array(1, 0, 50),
                    ),
                    'fill' => array('r' => 1, 'g' => 0, 'b' => 0),
                ),
            ),
        ),
    ),
));
$absoluteTransformCss = $fileContent($absoluteTransformResult, 'style.css');
$assert(str_contains($absoluteTransformCss, '.figma-node-absolute-child-rotated-child{width:80px;height:60px;position:absolute;left:40px;top:50px;background:#ff0000}'), 'absolute-visual-bounds-skip-css-transform');

$qualityAssets = array();
for ( $i = 1; $i <= 20; $i++ ) {
    $qualityAssets['quality-image-' . $i] = array('mime_type' => 'image/png', 'content' => 'image ' . $i);
}
$qualityChildren = array(
    array(
        'id'       => 'quality:header',
        'type'     => 'GROUP',
        'name'     => 'Site Header Raster Group',
        'width'    => 1440,
        'height'   => 120,
        'children' => array(
            array('id' => 'quality:header:1', 'type' => 'RECTANGLE', 'name' => 'Header slice 1', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-1'),
            array('id' => 'quality:header:2', 'type' => 'RECTANGLE', 'name' => 'Header slice 2', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-2'),
            array('id' => 'quality:header:3', 'type' => 'RECTANGLE', 'name' => 'Header slice 3', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-3'),
        ),
    ),
    array('id' => 'quality:offcanvas', 'type' => 'RECTANGLE', 'name' => 'Off canvas promo', 'x' => 2000, 'y' => -180, 'width' => 200, 'height' => 100, 'layoutPositioning' => 'ABSOLUTE', 'asset_id' => 'quality-image-4'),
);
for ( $i = 5; $i <= 12; $i++ ) {
    $qualityChildren[] = array('id' => 'quality:image:' . $i, 'type' => 'RECTANGLE', 'name' => 'Raster card ' . $i, 'width' => 80, 'height' => 60, 'asset_id' => 'quality-image-' . $i);
}
for ( $i = 13; $i <= 20; $i++ ) {
    $qualityChildren[] = array('id' => 'quality:vector:' . $i, 'type' => 'VECTOR', 'name' => 'Vector fallback ' . $i, 'width' => 24, 'height' => 24, 'asset_id' => 'quality-image-' . $i);
}
$qualityDiagnosticsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Quality Diagnostics Fixture',
    'assets' => $qualityAssets,
    'nodes'  => array(
        array(
            'id'       => 'quality:root',
            'type'     => 'FRAME',
            'name'     => 'Desktop fixed root',
            'width'    => 1440,
            'height'   => 1200,
            'layoutMode' => 'VERTICAL',
            'children' => $qualityChildren,
        ),
    ),
));
$qualitySignalCodes = $artifactQualitySignalCodes($qualityDiagnosticsResult);
$assert(! in_array('fixed_root_width', $qualitySignalCodes, true), 'quality-diagnostics-fixed-root-width-retired');
$qualityCss = $fileContent($qualityDiagnosticsResult, 'style.css');
$assert(str_contains($qualityCss, '.figma-node-quality-root-desktop-fixed-root{width:100%;max-width:1440px;margin-left:auto;margin-right:auto;'), 'quality-diagnostics-root-renders-fluid');
$assert(in_array('large_absolute_offsets', $qualitySignalCodes, true), 'quality-diagnostics-large-absolute-offsets');
$assert(in_array('image_heavy_landmark_candidate', $qualitySignalCodes, true), 'quality-diagnostics-image-heavy-landmark');
$assert(in_array('excessive_image_blocks', $qualitySignalCodes, true), 'quality-diagnostics-excessive-image-blocks');
$assert(in_array('excessive_vector_image_fallbacks', $qualitySignalCodes, true), 'quality-diagnostics-excessive-vector-fallbacks');
$largeOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'large_absolute_offsets');
$assert('quality:offcanvas' === ($largeOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-node');
$assert('quality:root' === ($largeOffsetSignal['sample_nodes'][0]['parent_id'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-parent');
$assert('figma-node-quality-offcanvas-off-canvas-promo' === ($largeOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-class');
$assert(2000.0 === (float) ($largeOffsetSignal['sample_nodes'][0]['left'] ?? 0), 'quality-diagnostics-large-absolute-offset-sample-left');
$visualOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'off_canvas_visual_nodes');
$assert('quality:offcanvas' === ($visualOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-off-canvas-visual-sample-node');
$assert('figma-node-quality-offcanvas-off-canvas-promo' === ($visualOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-off-canvas-visual-sample-class');
$assert('needs_review' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['status'] ?? null), 'quality-diagnostics-status-needs-review');
$assert('warn' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['quality_status'] ?? null), 'quality-diagnostics-quality-status-warn');
$qualitySignals = $qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
$excessiveImageSignal = null;
foreach ( is_array($qualitySignals) ? $qualitySignals : array() as $signal ) {
    if ( is_array($signal) && 'excessive_image_blocks' === ($signal['code'] ?? null) ) {
        $excessiveImageSignal = $signal;
        break;
    }
}
$assert(12 === ($excessiveImageSignal['threshold'] ?? null), 'quality-diagnostics-excessive-image-threshold');
$assert(($excessiveImageSignal['image_node_density'] ?? 0) > 0.35, 'quality-diagnostics-excessive-image-density');
$assert(! empty($excessiveImageSignal['sample_nodes']) && count($excessiveImageSignal['sample_nodes']) <= 10, 'quality-diagnostics-excessive-image-samples');
$assert(20 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary']['image_block_count'] ?? null), 'quality-diagnostics-image-block-summary-count');
$assert(22 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary']['total_node_count'] ?? null), 'quality-diagnostics-total-node-summary-count');
$assert('quality:root' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['selection']['selected_frames'][0]['frame_id'] ?? null), 'quality-diagnostics-selected-frame-id');
$assert(22 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['selection']['selected_frames'][0]['node_count'] ?? null), 'quality-diagnostics-selected-frame-node-count');

$normalImageAssets = array();
$normalImageChildren = array();
for ( $i = 1; $i <= 12; $i++ ) {
    $normalImageAssets['normal-image-' . $i] = array('mime_type' => 'image/png', 'content' => 'normal image ' . $i);
    $normalImageChildren[] = array('id' => 'normal:image:' . $i, 'type' => 'RECTANGLE', 'name' => 'Gallery image ' . $i, 'width' => 120, 'height' => 90, 'asset_id' => 'normal-image-' . $i);
}
for ( $i = 1; $i <= 32; $i++ ) {
    $normalImageChildren[] = array('id' => 'normal:text:' . $i, 'type' => 'TEXT', 'name' => 'Body copy ' . $i, 'characters' => 'Paragraph ' . $i, 'fontSize' => 16);
}
$normalImageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Normal Image Count Fixture',
    'assets' => $normalImageAssets,
    'nodes'  => array(
        array(
            'id'       => 'normal:root',
            'type'     => 'FRAME',
            'name'     => 'Editorial gallery page',
            'width'    => 960,
            'height'   => 1600,
            'layoutMode' => 'VERTICAL',
            'children' => $normalImageChildren,
        ),
    ),
));
$normalImageQuality = $normalImageResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality'] ?? array();
$assert(! in_array('excessive_image_blocks', $artifactQualitySignalCodes($normalImageResult), true), 'quality-diagnostics-normal-image-count-no-excessive-signal');
$assert(12 === ($normalImageQuality['summary']['image_block_count'] ?? null), 'quality-diagnostics-normal-image-count-summary');
$assert(($normalImageQuality['summary']['image_node_density'] ?? 1) < 0.35, 'quality-diagnostics-normal-image-density-summary');

$normalLocalPlacementResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Normal Local Placement Fixture',
    'nodes' => array(
        array(
            'id'       => 'normal-local:root',
            'type'     => 'FRAME',
            'name'     => 'Normal local root',
            'width'    => 480,
            'height'   => 320,
            'children' => array(
                array('id' => 'normal-local:card', 'type' => 'FRAME', 'name' => 'Local card', 'x' => 40, 'y' => 60, 'width' => 180, 'height' => 90, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                    array('id' => 'normal-local:copy', 'type' => 'TEXT', 'name' => 'Card copy', 'characters' => 'Normal placement', 'fontSize' => 16),
                )),
            ),
        ),
    ),
));
$normalLocalSignalCodes = $artifactQualitySignalCodes($normalLocalPlacementResult);
$assert(! in_array('large_absolute_offsets', $normalLocalSignalCodes, true), 'quality-diagnostics-normal-local-no-large-offset-signal');
$assert(! in_array('off_canvas_visual_nodes', $normalLocalSignalCodes, true), 'quality-diagnostics-normal-local-no-visual-off-canvas-signal');
$assert(0 === ($normalLocalPlacementResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['large_absolute_offset_count'] ?? null), 'quality-diagnostics-normal-local-large-offset-count-zero');
$assert(0 === ($normalLocalPlacementResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['off_canvas_visual_node_count'] ?? null), 'quality-diagnostics-normal-local-visual-offset-count-zero');

$multiPageOffCanvasResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multi Page Off Canvas Diagnostics Fixture',
    'nodes' => array(
        array(
            'id'       => 'multi-offset:canvas',
            'type'     => 'CANVAS',
            'name'     => 'Site pages',
            'children' => array(
                array(
                    'id'       => 'multi-offset:home',
                    'type'     => 'FRAME',
                    'name'     => 'Home',
                    'width'    => 400,
                    'height'   => 300,
                    'children' => array(
                        array('id' => 'multi-offset:home-title', 'type' => 'TEXT', 'name' => 'Home title', 'characters' => 'Home', 'fontSize' => 20),
                    ),
                ),
                array(
                    'id'       => 'multi-offset:about',
                    'type'     => 'FRAME',
                    'name'     => 'About',
                    'width'    => 400,
                    'height'   => 300,
                    'children' => array(
                        array('id' => 'multi-offset:hero', 'type' => 'FRAME', 'name' => 'Drifted hero', 'x' => 1200, 'y' => 0, 'width' => 120, 'height' => 80, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                            array('id' => 'multi-offset:hero-copy', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'About', 'fontSize' => 18),
                        )),
                    ),
                ),
            ),
        ),
    ),
), array('multi_page' => true, 'frame_ids' => array('multi-offset:home', 'multi-offset:about'), 'entry_frame_id' => 'multi-offset:home'));
$multiPageOffCanvasDiagnostics = $multiPageOffCanvasResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$multiPageOffCanvasSignal = null;
foreach ( is_array($multiPageOffCanvasDiagnostics['artifact_quality']['signals'] ?? null) ? $multiPageOffCanvasDiagnostics['artifact_quality']['signals'] : array() as $signal ) {
    if ( is_array($signal) && 'off_canvas_visual_nodes' === ($signal['code'] ?? null) ) {
        $multiPageOffCanvasSignal = $signal;
        break;
    }
}
$assert('multi_page' === ($multiPageOffCanvasDiagnostics['scope'] ?? null), 'quality-diagnostics-multi-page-off-canvas-scope');
$assert(1 === ($multiPageOffCanvasDiagnostics['layout']['off_canvas_visual_node_count'] ?? null), 'quality-diagnostics-multi-page-off-canvas-count');
$assert('multi-offset:hero' === ($multiPageOffCanvasSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-multi-page-off-canvas-sample-node');
$assert('about.html' === ($multiPageOffCanvasSignal['sample_nodes'][0]['page_path'] ?? null), 'quality-diagnostics-multi-page-off-canvas-sample-page');

$cleanQualityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Clean Quality Fixture',
    'nodes' => array(
        array(
            'id'       => 'clean:root',
            'type'     => 'FRAME',
            'name'     => 'Simple content',
            'width'    => 720,
            'height'   => 320,
            'layoutMode' => 'VERTICAL',
            'children' => array(
                array('id' => 'clean:title', 'type' => 'TEXT', 'name' => 'Page title', 'characters' => 'Clean page', 'fontSize' => 24),
                array('id' => 'clean:copy', 'type' => 'TEXT', 'name' => 'Page copy', 'characters' => 'A simple responsive-friendly page.', 'fontSize' => 16),
            ),
        ),
    ),
));
$assert(array() === $artifactQualitySignalCodes($cleanQualityResult), 'quality-diagnostics-clean-page-no-signals');
$assert('clean' === ($cleanQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['status'] ?? null), 'quality-diagnostics-clean-page-status');
$assert('pass' === ($cleanQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['quality_status'] ?? null), 'quality-diagnostics-clean-page-quality-status-pass');

$incompleteQualityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Incomplete Quality Fixture',
    'nodes' => array(
        array(
            'id'       => 'incomplete:root',
            'type'     => 'FRAME',
            'name'     => 'Incomplete page',
            'width'    => 720,
            'height'   => 320,
            'children' => array(
                array('id' => 'incomplete:image', 'type' => 'RECTANGLE', 'name' => 'Missing hero asset', 'width' => 320, 'height' => 180, 'asset_id' => 'missing-hero'),
                array('id' => 'incomplete:vector', 'type' => 'VECTOR', 'name' => 'Unsupported logo mark'),
            ),
        ),
    ),
));
$incompleteQuality = $incompleteQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality'] ?? array();
$assert('fail' === ($incompleteQuality['quality_status'] ?? null), 'quality-diagnostics-incomplete-quality-status-fail');
$assert(1 === ($incompleteQuality['summary']['missing_asset_nodes'] ?? null), 'quality-diagnostics-incomplete-missing-asset-count');
$assert(1 === ($incompleteQuality['summary']['vector_placeholders'] ?? null), 'quality-diagnostics-incomplete-vector-placeholder-count');

$offsetPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'     => 'Offset Board Fixture',
    'document' => array(
        'id'       => 'doc:1',
        'type'     => 'DOCUMENT',
        'name'     => 'Document',
        'children' => array(
            array(
                'id'       => 'canvas:1',
                'type'     => 'CANVAS',
                'name'     => 'Page 1',
                'children' => array(
                    array(
                        'id'                  => 'frame:selected',
                        'type'                => 'FRAME',
                        'name'                => 'Selected Website Page',
                        'absoluteBoundingBox' => array('x' => 3497, 'y' => 212, 'width' => 1440, 'height' => 900),
                        'children'            => array(
                            array(
                                'id'                  => 'frame:selected-card',
                                'type'                => 'FRAME',
                                'name'                => 'Hero Card',
                                'absoluteBoundingBox' => array('x' => 3537, 'y' => 252, 'width' => 320, 'height' => 160),
                                'layoutPositioning'   => 'ABSOLUTE',
                                'children'            => array(
                                    array(
                                        'id'                  => 'text:selected-title',
                                        'type'                => 'TEXT',
                                        'name'                => 'Hero title',
                                        'text'                => 'Selected page content',
                                        'fontSize'            => 32,
                                        'absoluteBoundingBox' => array('x' => 3561, 'y' => 280, 'width' => 220, 'height' => 44),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'                  => 'frame:off-canvas-one',
                        'type'                => 'FRAME',
                        'name'                => 'Off Canvas One',
                        'absoluteBoundingBox' => array('x' => 4680, 'y' => 212, 'width' => 1440, 'height' => 900),
                    ),
                    array(
                        'id'                  => 'frame:off-canvas-two',
                        'type'                => 'FRAME',
                        'name'                => 'Off Canvas Two',
                        'absoluteBoundingBox' => array('x' => -2200, 'y' => 212, 'width' => 1440, 'height' => 900),
                    ),
                ),
            ),
        ),
    ),
), array('frame_id' => 'frame:selected'));
$offsetPageHtml = $fileContent($offsetPageResult, 'index.html');
$offsetPageCss = $fileContent($offsetPageResult, 'style.css');
$assert('success' === ($offsetPageResult['status'] ?? null), 'offset-page-transform-success');
$assert(str_contains($offsetPageHtml, 'Selected page content'), 'offset-page-selected-content-rendered');
$assert(! str_contains($offsetPageHtml, 'Off Canvas One') && ! str_contains($offsetPageHtml, 'Off Canvas Two'), 'offset-page-off-canvas-siblings-omitted');
$assert(str_contains($offsetPageCss, '.figma-root{position:relative;width:100%}'), 'offset-page-root-shell-is-fluid');
$assert(str_contains($offsetPageCss, '.figma-node-frame-selected-selected-website-page{width:100%;max-width:1440px;margin-left:auto;margin-right:auto;height:900px;position:relative}'), 'offset-page-root-renders-fluid-centered');
$assert(str_contains($offsetPageCss, '.figma-node-frame-selected-card-hero-card{width:320px;height:160px;position:absolute;left:40px;top:40px}'), 'offset-page-child-rebased-position');
$assert(! str_contains($offsetPageCss, 'left:3497px') && ! str_contains($offsetPageCss, 'left:3537px') && ! str_contains($offsetPageCss, 'left:4680px'), 'offset-page-avoids-board-left-values');

$decorativeUnderlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Decorative Underlay Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'         => 'underlay:parent',
            'type'       => 'FRAME',
            'name'       => 'Flex Hero',
            'width'      => 1000,
            'height'     => 600,
            'layoutMode' => 'HORIZONTAL',
            'children'   => array(
                array(
                    'id'       => 'underlay:art',
                    'type'     => 'FRAME',
                    'name'     => 'Decorative Art',
                    'x'        => 40,
                    'y'        => -50,
                    'width'    => 900,
                    'height'   => 700,
                    'children' => array(
                        array(
                            'id'           => 'underlay:vector',
                            'type'         => 'VECTOR',
                            'name'         => 'Arc',
                            'width'        => 900,
                            'height'       => 700,
                            'fillGeometry' => array(array('commandsBlob' => 0)),
                        ),
                    ),
                ),
                array(
                    'id'       => 'underlay:copy',
                    'type'     => 'FRAME',
                    'name'     => 'Copy Stack',
                    'width'    => 320,
                    'height'   => 120,
                    'children' => array(
                        array(
                            'id'       => 'underlay:title',
                            'type'     => 'TEXT',
                            'name'     => 'Hero title',
                            'text'     => 'Content stays above art',
                            'fontSize' => 32,
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$decorativeUnderlayCss = $fileContent($decorativeUnderlayResult, 'style.css');
$decorativeUnderlayDiagnostics = $decorativeUnderlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$decorativeUnderlayLayout = $decorativeUnderlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
$decorativeUnderlayArt = $findVisualNode($decorativeUnderlayResult, 'underlay:art');
$decorativeUnderlayVector = $findVisualNode($decorativeUnderlayResult, 'underlay:vector');
$assert(str_contains($decorativeUnderlayCss, '.figma-node-underlay-parent-flex-hero{width:1000px;height:600px;position:relative;display:flex;flex-direction:row}'), 'decorative-underlay-parent-relative');
$assert(str_contains($decorativeUnderlayCss, '.figma-node-underlay-art-decorative-art{width:900px;height:700px;position:absolute;left:40px;top:-50px;z-index:0;pointer-events:none}'), 'decorative-underlay-absolute');
$assert(str_contains($decorativeUnderlayCss, '.figma-node-underlay-copy-copy-stack{width:320px;height:120px;position:relative;z-index:1;flex-shrink:0}'), 'decorative-underlay-content-stacks-above');
$assert(array('x' => 40.0, 'y' => -50.0, 'width' => 900.0, 'height' => 700.0) === ($decorativeUnderlayArt['rect'] ?? null), 'decorative-underlay-visual-map-includes-css-offset');
$assert(array('x' => 40.0, 'y' => -50.0, 'width' => 900.0, 'height' => 700.0) === ($decorativeUnderlayVector['rect'] ?? null), 'decorative-underlay-child-visual-map-inherits-css-offset');
$assert(0 === ($decorativeUnderlayLayout['large_absolute_offset_count'] ?? null), 'decorative-underlay-not-large-absolute-offset');
$assert(1 === ($decorativeUnderlayDiagnostics['count'] ?? null), 'decorative-underlay-diagnostics-count');
$assert(array(
    'node_id'       => 'underlay:art',
    'name'          => 'Decorative Art',
    'parent_id'     => 'underlay:parent',
    'parent_name'   => 'Flex Hero',
    'width'         => 900,
    'height'        => 700,
    'parent_width'  => 1000,
    'parent_height' => 600,
) === ($decorativeUnderlayDiagnostics['nodes'][0] ?? null), 'decorative-underlay-diagnostics-node-entry');

$contentCardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Content Card Fixture',
    'nodes' => array(
        array(
            'id'         => 'card:parent',
            'type'       => 'FRAME',
            'name'       => 'Card Parent',
            'width'      => 1000,
            'height'     => 600,
            'layoutMode' => 'HORIZONTAL',
            'children'   => array(
                array(
                    'id'       => 'card:large',
                    'type'     => 'FRAME',
                    'name'     => 'Large Content Card',
                    'width'    => 850,
                    'height'   => 520,
                    'children' => array(
                        array('id' => 'card:title', 'type' => 'TEXT', 'name' => 'Card title', 'text' => 'Real content'),
                    ),
                ),
                array('id' => 'card:sibling', 'type' => 'TEXT', 'name' => 'Sibling copy', 'text' => 'Sibling'),
            ),
        ),
    ),
));
$contentCardCss = $fileContent($contentCardResult, 'style.css');
$contentCardUnderlays = $contentCardResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$assert(str_contains($contentCardCss, '.figma-node-card-large-large-content-card{width:850px;height:520px;flex-shrink:0}'), 'large-content-card-remains-flex-child');
$assert(0 === ($contentCardUnderlays['count'] ?? null), 'large-content-card-not-decorative-underlay-diagnostic');

$imageUnderlayGuardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Image Underlay Guard Fixture',
    'assets' => array(
        'guard-image' => array(
            'name'      => 'Guard Image',
            'mime_type' => 'image/svg+xml',
            'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
        ),
    ),
    'nodes'  => array(
        array(
            'id'         => 'imageguard:parent',
            'type'       => 'FRAME',
            'name'       => 'Image Parent',
            'width'      => 1000,
            'height'     => 600,
            'layoutMode' => 'HORIZONTAL',
            'children'   => array(
                array(
                    'id'       => 'imageguard:photo',
                    'type'     => 'RECTANGLE',
                    'name'     => 'Large Photo',
                    'width'    => 900,
                    'height'   => 520,
                    'asset_id' => 'guard-image',
                ),
                array('id' => 'imageguard:title', 'type' => 'TEXT', 'name' => 'Hero title', 'text' => 'Photo should stay in flow'),
            ),
        ),
    ),
));
$imageUnderlayGuardCss = $fileContent($imageUnderlayGuardResult, 'style.css');
$imageUnderlayGuardUnderlays = $imageUnderlayGuardResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$assert(str_contains($imageUnderlayGuardCss, '.figma-node-imageguard-photo-large-photo{width:900px;height:520px;background-image:url("assets/guard-image.svg");background-size:cover;background-position:center;flex-shrink:0}'), 'image-backed-child-remains-flex-child');
$assert(0 === ($imageUnderlayGuardUnderlays['count'] ?? null), 'image-backed-child-not-decorative-underlay-diagnostic');

$clippedDecorativeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Clipped Decorative Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'           => 'clip:parent',
            'type'         => 'FRAME',
            'name'         => 'Clipped Parent',
            'width'        => 200,
            'height'       => 100,
            'clipsContent' => true,
            'children'     => array(
                array(
                    'id'       => 'clip:hidden-group',
                    'type'     => 'GROUP',
                    'name'     => 'Off canvas decorative group',
                    'x'        => -260,
                    'y'        => 10,
                    'width'    => 120,
                    'height'   => 60,
                    'children' => array(
                        array(
                            'id'           => 'clip:hidden-vector',
                            'type'         => 'VECTOR',
                            'name'         => 'Hidden vector flourish',
                            'x'            => 0,
                            'y'            => 0,
                            'width'        => 120,
                            'height'       => 60,
                            'fillGeometry' => array(array('commandsBlob' => 0)),
                        ),
                    ),
                ),
                array(
                    'id'           => 'clip:partial-vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Partly clipped vector flourish',
                    'x'            => -20,
                    'y'            => 20,
                    'width'        => 60,
                    'height'       => 30,
                    'fillGeometry' => array(array('commandsBlob' => 0)),
                ),
                array(
                    'id'     => 'clip:copy',
                    'type'   => 'TEXT',
                    'name'   => 'Visible copy',
                    'text'   => 'Real content remains mapped',
                    'x'      => -10,
                    'y'      => 70,
                    'width'  => 140,
                    'height' => 20,
                ),
            ),
        ),
    ),
));
$clippedDecorativeCss = $fileContent($clippedDecorativeResult, 'style.css');
$clippedDecorativeHtml = $fileContent($clippedDecorativeResult, 'index.html');
$clippedDecorativeDiagnostics = $clippedDecorativeResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
$clippedHiddenGroup = $findVisualNode($clippedDecorativeResult, 'clip:hidden-group');
$clippedHiddenVector = $findVisualNode($clippedDecorativeResult, 'clip:hidden-vector');
$clippedPartialVector = $findVisualNode($clippedDecorativeResult, 'clip:partial-vector');
$clippedVisibleCopy = $findVisualNode($clippedDecorativeResult, 'clip:copy');
$assert(str_contains($clippedDecorativeCss, '.figma-node-clip-parent-clipped-parent{width:200px;height:100px;overflow:hidden;position:relative}'), 'clipped-decorative-parent-overflow-hidden');
$assert(! str_contains($clippedDecorativeHtml, 'Off canvas decorative group') && ! str_contains($clippedDecorativeCss, 'figma-node-clip-hidden-group'), 'fully-clipped-decorative-node-not-emitted');
$assert(null === $clippedHiddenGroup && null === $clippedHiddenVector, 'fully-clipped-decorative-nodes-omitted-from-visual-map');
$assert(array('x' => -20.0, 'y' => 20.0, 'width' => 60.0, 'height' => 30.0) === ($clippedPartialVector['rect'] ?? null), 'partly-clipped-decorative-node-keeps-source-rect');
$assert(array('x' => 0.0, 'y' => 20.0, 'width' => 40.0, 'height' => 30.0) === ($clippedPartialVector['visible_rect'] ?? null), 'partly-clipped-decorative-node-visible-rect-intersection');
$assert(array('x' => -10.0, 'y' => 70.0, 'width' => 140.0, 'height' => 20.0) === ($clippedVisibleCopy['rect'] ?? null), 'clipped-content-node-keeps-source-rect');
$assert(0 === ($clippedDecorativeDiagnostics['large_absolute_offset_count'] ?? null), 'fully-clipped-decorative-node-not-counted-as-large-offset');

$gamesControlLayoutResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Games Control Layout Guard Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'           => 'games:control',
            'type'         => 'FRAME',
            'name'         => 'Games control',
            'width'        => 120,
            'height'       => 40,
            'clipsContent' => true,
            'children'     => array(
                array(
                    'id'           => 'games:icon',
                    'type'         => 'VECTOR',
                    'name'         => 'Games icon',
                    'x'            => -8,
                    'y'            => 8,
                    'width'        => 24,
                    'height'       => 24,
                    'fillGeometry' => array(array('commandsBlob' => 0)),
                ),
                array(
                    'id'     => 'games:label',
                    'type'   => 'TEXT',
                    'name'   => 'Games label',
                    'text'   => 'Games',
                    'x'      => 24,
                    'y'      => 10,
                    'width'  => 60,
                    'height' => 20,
                ),
            ),
        ),
    ),
), array(
    'generated_dom_boxes' => array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'boxes'  => array(
            array('node_id' => 'games:control', 'rect' => array('x' => 0, 'y' => 0, 'width' => 120, 'height' => 40)),
            array('node_id' => 'games:icon', 'rect' => array('x' => 0, 'y' => 8, 'width' => 16, 'height' => 24)),
            array('node_id' => 'games:label', 'rect' => array('x' => 24, 'y' => 10, 'width' => 60, 'height' => 20)),
        ),
    ),
    'layout_mismatch_threshold'      => 1,
    'layout_mismatch_size_threshold' => 1,
));
$gamesControlDiagnostics = $gamesControlLayoutResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
$gamesControlIcon = $findVisualNode($gamesControlLayoutResult, 'games:icon');
$assert(array('x' => -8.0, 'y' => 8.0, 'width' => 24.0, 'height' => 24.0) === ($gamesControlIcon['rect'] ?? null), 'games-control-clipped-icon-source-rect');
$assert(array('x' => 0.0, 'y' => 8.0, 'width' => 16.0, 'height' => 24.0) === ($gamesControlIcon['visible_rect'] ?? null), 'games-control-clipped-icon-visible-rect-intersection');
$assert(0 === ($gamesControlDiagnostics['layout_mismatch_count'] ?? null), 'games-control-layout-mismatch-count-zero');
$assert('pass' === ($gamesControlDiagnostics['layout_mismatch_status'] ?? null), 'games-control-layout-mismatch-pass');

$flippedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Flipped Vector Layout Guard Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'flip:parent',
            'type'     => 'FRAME',
            'name'     => 'Flip parent',
            'width'    => 120,
            'height'   => 80,
            'children' => array(
                array(
                    'id'           => 'flip:vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Flipped vector',
                    'x'            => 20,
                    'y'            => 10,
                    'width'        => 60,
                    'height'       => 30,
                    'transform'    => array('m00' => -1, 'm01' => 0, 'm02' => 0, 'm10' => 0, 'm11' => 1, 'm12' => 0),
                    'fillGeometry' => array(array('commandsBlob' => 0)),
                ),
            ),
        ),
    ),
), array(
    'generated_dom_boxes' => array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'boxes'  => array(
            array('node_id' => 'flip:parent', 'rect' => array('x' => 0, 'y' => 0, 'width' => 120, 'height' => 80)),
            array('node_id' => 'flip:vector', 'rect' => array('x' => -40, 'y' => 10, 'width' => 60, 'height' => 30)),
        ),
    ),
    'layout_mismatch_threshold'      => 1,
    'layout_mismatch_size_threshold' => 1,
));
$flippedVectorDiagnostics = $flippedVectorResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
$flippedVectorNode = $findVisualNode($flippedVectorResult, 'flip:vector');
$assert(str_contains($fileContent($flippedVectorResult, 'style.css'), 'transform:matrix(-1,0,0,1,0,0);transform-origin:0 0'), 'flipped-vector-css-transform-matrix');
$assert(array('x' => -40.0, 'y' => 10.0, 'width' => 60.0, 'height' => 30.0) === ($flippedVectorNode['rect'] ?? null), 'flipped-vector-visual-map-applies-matrix');
$assert(0 === ($flippedVectorDiagnostics['layout_mismatch_count'] ?? null), 'flipped-vector-layout-mismatch-count-zero');
$assert('pass' === ($flippedVectorDiagnostics['layout_mismatch_status'] ?? null), 'flipped-vector-layout-mismatch-pass');

$transformedParentVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Transformed Parent Vector Layout Guard Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'        => 'parent-transform:group',
            'type'      => 'GROUP',
            'name'      => 'Rotated vector group',
            'width'     => 120,
            'height'    => 80,
            'transform' => array('m00' => 0, 'm01' => -1, 'm02' => 0, 'm10' => 1, 'm11' => 0, 'm12' => 0),
            'children'  => array(
                array(
                    'id'           => 'parent-transform:vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Absolute child vector',
                    'x'            => 20,
                    'y'            => 10,
                    'width'        => 60,
                    'height'       => 30,
                    'layout'       => array('positioning' => 'absolute'),
                    'fillGeometry' => array(array('commandsBlob' => 0)),
                ),
            ),
        ),
    ),
), array(
    'generated_dom_boxes' => array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'boxes'  => array(
            array('node_id' => 'parent-transform:group', 'rect' => array('x' => -80, 'y' => 0, 'width' => 80, 'height' => 120)),
            array('node_id' => 'parent-transform:vector', 'rect' => array('x' => -40, 'y' => 20, 'width' => 30, 'height' => 60)),
        ),
    ),
    'layout_mismatch_threshold'      => 1,
    'layout_mismatch_size_threshold' => 1,
));
$transformedParentVectorDiagnostics = $transformedParentVectorResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
$transformedParentVectorGroup = $findVisualNode($transformedParentVectorResult, 'parent-transform:group');
$transformedParentVectorNode = $findVisualNode($transformedParentVectorResult, 'parent-transform:vector');
$assert(array('x' => -80.0, 'y' => 0.0, 'width' => 80.0, 'height' => 120.0) === ($transformedParentVectorGroup['rect'] ?? null), 'transformed-parent-vector-group-visual-map-applies-matrix');
$assert(array('x' => -40.0, 'y' => 20.0, 'width' => 30.0, 'height' => 60.0) === ($transformedParentVectorNode['rect'] ?? null), 'transformed-parent-vector-child-visual-map-composes-parent-matrix');
$assert(0 === ($transformedParentVectorDiagnostics['layout_mismatch_count'] ?? null), 'transformed-parent-vector-layout-mismatch-count-zero');
$assert('pass' === ($transformedParentVectorDiagnostics['layout_mismatch_status'] ?? null), 'transformed-parent-vector-layout-mismatch-pass');

$transparentVisualMapResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Transparent Visual Map Fixture',
    'nodes' => array(
        array(
            'id'       => 'transparent:parent',
            'type'     => 'FRAME',
            'name'     => 'Invisible transformed shell',
            'width'    => 200,
            'height'   => 0,
            'opacity'  => 0,
            'rotation' => -30,
            'children' => array(
                array(
                    'id'     => 'transparent:child',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Inherited invisible child',
                    'width'  => 200,
                    'height' => 80,
                    'fill'   => array('r' => 1, 'g' => 1, 'b' => 1),
                ),
            ),
        ),
        array(
            'id'     => 'transparent:sibling',
            'type'   => 'RECTANGLE',
            'name'   => 'Visible sibling',
            'y'      => 100,
            'width'  => 40,
            'height' => 20,
        ),
    ),
));
$assert(null === $findVisualNode($transparentVisualMapResult, 'transparent:parent'), 'transparent-parent-omitted-from-visual-map');
$assert(null === $findVisualNode($transparentVisualMapResult, 'transparent:child'), 'transparent-child-omitted-from-visual-map');
$assert(null !== $findVisualNode($transparentVisualMapResult, 'transparent:sibling'), 'transparent-sibling-remains-in-visual-map');

$oversizedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Oversized Vector Bounds Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'           => 'vector:oversized-bounds',
            'type'         => 'VECTOR',
            'name'         => 'Oversized Bounds',
            'width'        => 5,
            'height'       => 5,
            'fillGeometry' => array(array('commandsBlob' => 0)),
        ),
    ),
));
$oversizedVectorHtml = $fileContent($oversizedVectorResult, 'index.html');
$oversizedVectorCss = $fileContent($oversizedVectorResult, 'style.css');
$assert(str_contains($oversizedVectorHtml, 'viewBox="0 0 10 10"'), 'oversized-vector-viewbox-uses-path-bounds');
$assert(str_contains($oversizedVectorCss, '.figma-node-vector-oversized-bounds-oversized-bounds{width:5px;height:5px'), 'oversized-vector-css-keeps-node-size');

$edgeAlignedFilledVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Edge Aligned Filled Vector Fixture',
    'nodes' => array(
        array(
            'id'                 => 'vector:edge-aligned-fill',
            'type'               => 'VECTOR',
            'name'               => 'Edge Aligned Fill',
            'width'              => 10,
            'height'             => 10,
            'fillPaints'         => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
            'figma_vector_paths' => array(array('data' => 'M0 0L10 0L10 10L0 10Z')),
        ),
    ),
));
$edgeAlignedFilledVectorHtml = $fileContent($edgeAlignedFilledVectorResult, 'index.html');
$assert(str_contains($edgeAlignedFilledVectorHtml, 'viewBox="0 0 10 10"'), 'edge-aligned-filled-vector-viewbox-keeps-intrinsic-bounds');
$assert(! str_contains($edgeAlignedFilledVectorHtml, 'viewBox="-0.5 -0.5 11 11"'), 'edge-aligned-filled-vector-no-stroke-padding');

$largeDecodedPath = 'M 0 0' . str_repeat(' L 10 10', 3000) . ' Z';
$largeDecodedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Large Decoded Vector Fixture',
    'nodes' => array(
        array(
            'id'                 => 'vector:large-decoded',
            'type'               => 'VECTOR',
            'name'               => 'Large Decoded Vector',
            'width'              => 10,
            'height'             => 10,
            'figma_vector_paths' => array(array('data' => $largeDecodedPath, 'source' => 'strokeGeometry')),
        ),
        array(
            'id'       => 'vector:large-raw',
            'type'     => 'VECTOR',
            'name'     => 'Large Raw Vector',
            'width'    => 10,
            'height'   => 10,
            'pathData' => $largeDecodedPath,
        ),
    ),
));
$largeDecodedVectorHtml = $fileContent($largeDecodedVectorResult, 'index.html');
$largeDecodedVectorDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $largeDecodedVectorResult['diagnostics'] ?? array()
);
$largeDecodedVectorDiagnostics = $largeDecodedVectorResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
$largeRawPlaceholder = null;
foreach ( $largeDecodedVectorDiagnostics['placeholder_nodes'] ?? array() as $placeholderNode ) {
    if ( is_array($placeholderNode) && 'vector:large-raw' === ($placeholderNode['node_id'] ?? null) ) {
        $largeRawPlaceholder = $placeholderNode;
        break;
    }
}
$assert(str_contains($largeDecodedVectorHtml, 'data-figma-node-id="vector:large-decoded"') && str_contains($largeDecodedVectorHtml, 'data-figma-vector="true"'), 'large-decoded-vector-path-renders');
$assert(str_contains($largeDecodedVectorHtml, 'data-figma-node-id="vector:large-raw"') && str_contains($largeDecodedVectorHtml, 'data-figma-unsupported-vector="true"'), 'large-raw-vector-path-remains-capped');
$assert(in_array('unsupported_vector_node_placeholder', $largeDecodedVectorDiagnosticCodes, true), 'large-raw-vector-placeholder-diagnostic');
$assert('oversized_path_data' === ($largeRawPlaceholder['reason'] ?? null), 'large-raw-vector-placeholder-reason');
$assert(array('pathData') === ($largeRawPlaceholder['source_fields'] ?? null), 'large-raw-vector-placeholder-source-field');
$assert(1 === ($largeDecodedVectorDiagnostics['placeholder_reasons']['oversized_path_data'] ?? null), 'large-raw-vector-placeholder-reason-count');

// Figma REST/plugin geometry shape: fillGeometry/strokeGeometry carry ready-to-use
// SVG path strings. They must emit real inline <svg><path> (not placeholders) and be
// counted by the vector-decode-coverage diagnostic.
$readyGeometryVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Ready Geometry Vector Fixture',
    'nodes' => array(
        array(
            'id'           => 'vector:ready-fill-geometry',
            'type'         => 'VECTOR',
            'name'         => 'Brand Logo',
            'width'        => 24,
            'height'       => 24,
            'fillPaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
            'fillGeometry' => array(
                array('path' => 'M 0 0 L 24 0 L 24 24 L 0 24 Z', 'windingRule' => 'NONZERO'),
            ),
        ),
        array(
            'id'     => 'vector:no-geometry',
            'type'   => 'VECTOR',
            'name'   => 'Geometryless Mark',
            'width'  => 24,
            'height' => 24,
        ),
    ),
));
$readyGeometryVectorHtml = $fileContent($readyGeometryVectorResult, 'index.html');
$readyGeometryVectors = $readyGeometryVectorResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
$readyGeometryCoverage = $readyGeometryVectors['decode_coverage'] ?? array();
$readyGeometryNode = null;
foreach ( $readyGeometryVectors['placeholder_nodes'] ?? array() as $placeholderNode ) {
    if ( is_array($placeholderNode) && 'vector:ready-fill-geometry' === ($placeholderNode['node_id'] ?? null) ) {
        $readyGeometryNode = $placeholderNode;
        break;
    }
}
$assert(str_contains($readyGeometryVectorHtml, 'data-figma-node-id="vector:ready-fill-geometry"') && str_contains($readyGeometryVectorHtml, 'data-figma-vector="true"'), 'ready-fill-geometry-renders-inline-svg');
$assert(str_contains($readyGeometryVectorHtml, '<path d="M0 0L24 0 24 24 0 24Z"') && str_contains($readyGeometryVectorHtml, 'fill-rule="nonzero"'), 'ready-fill-geometry-emits-path');
$assert(null === $readyGeometryNode, 'ready-fill-geometry-is-not-a-placeholder');
$assert(2 === (int) ($readyGeometryCoverage['vector_nodes'] ?? 0), 'ready-geometry-decode-coverage-node-count');
$assert(1 === (int) ($readyGeometryCoverage['decoded_to_svg'] ?? 0), 'ready-geometry-decode-coverage-decoded-count');
$assert(1 === (int) ($readyGeometryCoverage['placeholders'] ?? 0), 'ready-geometry-decode-coverage-placeholder-count');
$assert(0.5 === ($readyGeometryCoverage['coverage_ratio'] ?? null), 'ready-geometry-decode-coverage-ratio');
$assert(1 === (int) ($readyGeometryCoverage['placeholder_reason_categories']['no_geometry_available'] ?? 0), 'ready-geometry-decode-coverage-no-geometry-category');

$externalizedVectorPath = 'M 0.0000 0.0000' . str_repeat(' L 10.000001 10.000001', 12000) . ' Z';
$externalizedEquivalentVectorPath = 'M0,0' . str_repeat('L10,10', 12000) . 'Z';
$externalizedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Externalized Vector Fixture',
    'nodes' => array(
        array(
            'id'                 => 'vector:externalized-a',
            'type'               => 'VECTOR',
            'name'               => 'Externalized Vector',
            'width'              => 10,
            'height'             => 10,
            'figma_vector_paths' => array(array('data' => $externalizedVectorPath, 'source' => 'strokeGeometry')),
        ),
        array(
            'id'                 => 'vector:externalized-b',
            'type'               => 'VECTOR',
            'name'               => 'Externalized Vector',
            'width'              => 10,
            'height'             => 10,
            'figma_vector_paths' => array(array('data' => $externalizedEquivalentVectorPath, 'source' => 'strokeGeometry')),
        ),
    ),
));
$externalizedVectorHtml = $fileContent($externalizedVectorResult, 'index.html');
$externalizedVectorCss = $fileContent($externalizedVectorResult, 'style.css');
$externalizedVectorAssets = array_values(array_filter(
    $externalizedVectorResult['assets'] ?? array(),
    static fn (array $asset): bool => str_starts_with((string) ($asset['path'] ?? ''), 'assets/vector-') && 'image/svg+xml' === ($asset['mime_type'] ?? null)
));
$assert(2 === substr_count($externalizedVectorHtml, 'class="figma-vector-asset"'), 'large-vector-externalized-img-references');
$assert(1 === count($externalizedVectorAssets), 'large-vector-externalized-deduped-asset');
$assert(str_contains($externalizedVectorCss, '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}'), 'large-vector-asset-css');
$externalizedVectorAssetContent = $fileContent($externalizedVectorResult, (string) ($externalizedVectorAssets[0]['path'] ?? ''));
$assert(str_contains($externalizedVectorAssetContent, '<svg '), 'large-vector-externalized-svg-content');
$assert(str_contains($externalizedVectorAssetContent, 'd="M0 0L10 10 10 10'), 'large-vector-path-data-canonicalized');
$assert(! str_contains($externalizedVectorAssetContent, '10.000001'), 'large-vector-path-data-precision-reduced');
$assert(! str_contains($externalizedVectorAssetContent, 'L10 10L10 10'), 'large-vector-path-data-repeated-commands-elided');
$externalizedVectorDiagnostics = $externalizedVectorResult['source_reports']['figma']['html']['transform_diagnostics']['generated_svg_assets'] ?? array();
$assert('blocks-engine/figma-transformer/generated-svg-assets/v1' === ($externalizedVectorDiagnostics['schema'] ?? null), 'generated-svg-assets-diagnostics-schema');
$assert(1 === ($externalizedVectorDiagnostics['count'] ?? null), 'generated-svg-assets-diagnostics-count');
$assert(($externalizedVectorDiagnostics['bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-bytes');
$assert(($externalizedVectorDiagnostics['gzip_bytes'] ?? 0) > 0, 'generated-svg-assets-diagnostics-gzip-bytes');
$assert(1 === ($externalizedVectorDiagnostics['path_element_count'] ?? null), 'generated-svg-assets-diagnostics-path-element-count');
$assert(($externalizedVectorDiagnostics['path_data_bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-path-data-bytes');
$assert(($externalizedVectorDiagnostics['largest_path_data_bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-largest-path-data-bytes');
$assert(1 === ($externalizedVectorDiagnostics['unique_path_data_count'] ?? null), 'generated-svg-assets-diagnostics-unique-path-data-count');
$assert(0 === ($externalizedVectorDiagnostics['duplicate_path_data_count'] ?? null), 'generated-svg-assets-diagnostics-duplicate-path-data-count');
$assert(array((string) ($externalizedVectorAssets[0]['path'] ?? '')) === ($externalizedVectorDiagnostics['paths'] ?? null), 'generated-svg-assets-diagnostics-paths');
$assert(1 === ($externalizedVectorDiagnostics['assets'][0]['path_element_count'] ?? null), 'generated-svg-assets-diagnostics-asset-path-element-count');
$assert(($externalizedVectorDiagnostics['assets'][0]['path_data_bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-asset-path-data-bytes');
$assert(1 === ($externalizedVectorDiagnostics['assets'][0]['unique_path_data_count'] ?? null), 'generated-svg-assets-diagnostics-asset-unique-path-data-count');

$starVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Star Vector Fixture',
    'nodes' => array(
        array(
            'id'                 => 'vector:star',
            'type'               => 'STAR',
            'name'               => 'Rating Star',
            'width'              => 20,
            'height'             => 20,
            'figma_vector_paths' => array(array('data' => 'M 10 0 L 12 7 L 20 7 L 14 12 L 16 20 L 10 15 L 4 20 L 6 12 L 0 7 L 8 7 Z', 'source' => 'fillGeometry')),
        ),
        array(
            'id'     => 'vector:primitive-star',
            'type'   => 'STAR',
            'name'   => 'Primitive Rating Star',
            'width'  => 20,
            'height' => 20,
            'fill'   => array('r' => 1, 'g' => 0.5, 'b' => 0),
        ),
        array(
            'id'         => 'vector:primitive-polygon',
            'type'       => 'REGULAR_POLYGON',
            'name'       => 'Primitive Polygon',
            'width'      => 20,
            'height'     => 20,
            'pointCount' => 6,
        ),
    ),
));
$starVectorHtml = $fileContent($starVectorResult, 'index.html');
$assert(str_contains($starVectorHtml, 'data-figma-node-id="vector:star"') && str_contains($starVectorHtml, 'data-figma-vector="true"'), 'star-vector-path-renders');
$assert(str_contains($starVectorHtml, 'data-figma-node-id="vector:primitive-star"') && str_contains($starVectorHtml, 'data-figma-vector="true"'), 'primitive-star-vector-renders');
$assert(str_contains($starVectorHtml, 'data-figma-node-id="vector:primitive-polygon"') && str_contains($starVectorHtml, 'data-figma-vector="true"'), 'primitive-polygon-vector-renders');
$assert(! str_contains($starVectorHtml, 'data-figma-unsupported-vector="true"'), 'star-vector-path-not-placeholder');

$geometrylessVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Geometryless Vector Fixture',
    'nodes' => array(
        array(
            'id'         => 'vector:geometryless-parent',
            'type'       => 'FRAME',
            'name'       => 'Geometryless vector parent',
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.6))),
            'children'   => array(
                array(
                    'id'     => 'vector:geometryless',
                    'type'   => 'VECTOR',
                    'name'   => 'Geometryless vector bounds',
                    'width'  => 16,
                    'height' => 8,
                ),
            ),
        ),
    ),
));
$geometrylessVectorHtml = $fileContent($geometrylessVectorResult, 'index.html');
$geometrylessVectorDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $geometrylessVectorResult['diagnostics'] ?? array()
);
$assert(str_contains($geometrylessVectorHtml, 'data-figma-node-id="vector:geometryless"') && str_contains($geometrylessVectorHtml, '<rect x="0" y="0" width="16" height="8" fill="#336699"/>'), 'geometryless-vector-renders-inherited-color-bounds');
$assert(! in_array('unsupported_vector_node_placeholder', $geometrylessVectorDiagnosticCodes, true), 'geometryless-vector-no-placeholder-diagnostic');

$simpleRectNetworkPrefix = hex2bin('0400000004000000000000000000000000000000000000000000000000008043');
$simpleRectNetworkBlob = str_pad(false === $simpleRectNetworkPrefix ? '' : $simpleRectNetworkPrefix, 172, "\0");
$singleLoopNetworkBlob = static function (array $points, array $segments, array $regionEntries, ?int $regionSegmentCount = null): string {
    $vertexCount = count($points);
    $segmentCount = count($segments);
    $blob = pack('V3', $vertexCount, $segmentCount, 1) . str_repeat("\0", ( $vertexCount * 20 ) + ( $segmentCount * 16 ) + 12 + ( $vertexCount * 8 ));
    foreach ( $points as $index => $point ) {
        $blob = substr_replace($blob, pack('g', $point[0]) . pack('g', $point[1]), 12 + ( $index * 20 ) + 4, 8);
    }

    $segmentOffset = 12 + ( $vertexCount * 20 );
    foreach ( $segments as $index => $segment ) {
        $blob = substr_replace($blob, pack('V2', $segment[0], $segment[1]), $segmentOffset + ( $index * 16 ), 8);
    }

    $regionOffset = $segmentOffset + ( $segmentCount * 16 );
    $blob = substr_replace($blob, pack('V3', $regionSegmentCount ?? count($regionEntries), 0, 0), $regionOffset, 12);
    foreach ( $regionEntries as $index => $entry ) {
        $blob = substr_replace($blob, pack('V2', $entry[0], $entry[1]), $regionOffset + 12 + ( $index * 8 ), 8);
    }

    return $blob;
};
$closedRectNetworkBlob = pack('V3', 4, 4, 1) . str_repeat("\0", 188);
foreach ( array(array(0.0, 0.0), array(12.0, 0.0), array(12.0, 6.0), array(0.0, 6.0)) as $index => $point ) {
    $offset = 12 + ( $index * 20 ) + 4;
    $closedRectNetworkBlob = substr_replace($closedRectNetworkBlob, pack('g', $point[0]) . pack('g', $point[1]), $offset, 8);
}
$nonRectNetworkBlob = pack('V3', 4, 4, 1) . str_repeat("\0", 188);
foreach ( array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)) as $index => $point ) {
    $offset = 12 + ( $index * 20 ) + 4;
    $nonRectNetworkBlob = substr_replace($nonRectNetworkBlob, pack('g', $point[0]) . pack('g', $point[1]), $offset, 8);
}
$vectorDataResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Vector Data Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob), array('bytes' => "\xff"), array('bytes' => $simpleRectNetworkBlob), array('bytes' => $closedRectNetworkBlob), array('bytes' => $nonRectNetworkBlob)),
    'nodes' => array(
        array(
            'id'         => 'vector:data',
            'type'       => 'VECTOR',
            'name'       => 'Vector Data Path',
            'width'      => 10,
            'height'     => 10,
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
            'vectorData' => array('vectorNetworkBlob' => 0),
        ),
        array(
            'id'         => 'vector:data-malformed',
            'type'       => 'VECTOR',
            'name'       => 'Malformed Vector Data Path',
            'width'      => 10,
            'height'     => 10,
            'vectorData' => array('vectorNetworkBlob' => 1),
        ),
        array(
            'id'         => 'vector:data-painted-fallback',
            'type'       => 'VECTOR',
            'name'       => 'Painted Network Fallback',
            'width'      => 12,
            'height'     => 6,
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1))),
            'vectorData' => array('vectorNetworkBlob' => 1),
        ),
        array(
            'id'         => 'vector:data-simple-rect-network',
            'type'       => 'VECTOR',
            'name'       => 'Simple Rect Network',
            'width'      => 12,
            'height'     => 6,
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0.5, 'b' => 1))),
            'vectorData' => array('vectorNetworkBlob' => 2),
        ),
        array(
            'id'         => 'vector:data-closed-rect-network',
            'type'       => 'VECTOR',
            'name'       => 'Closed Rect Network',
            'width'      => 12,
            'height'     => 6,
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.6))),
            'vectorData' => array('vectorNetworkBlob' => 3),
        ),
        array(
            'id'         => 'vector:data-non-rect-network',
            'type'       => 'VECTOR',
            'name'       => 'Non Rect Network',
            'width'      => 12,
            'height'     => 6,
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.6, 'g' => 0.4, 'b' => 0.2))),
            'vectorData' => array('vectorNetworkBlob' => 4),
        ),
    ),
));
$vectorDataHtml = $fileContent($vectorDataResult, 'index.html');
$vectorNetworkDiagnostic = null;
foreach ( $vectorDataResult['diagnostics'] ?? array() as $diagnostic ) {
    if ( is_array($diagnostic) && 'unsupported_vector_network_blob' === ($diagnostic['code'] ?? null) ) {
        $vectorNetworkDiagnostic = $diagnostic;
        break;
    }
}
$vectorDataDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $vectorDataResult['diagnostics'] ?? array()
);
$assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data"') && str_contains($vectorDataHtml, 'data-figma-vector="true"'), 'vector-data-renders-svg');
$assert(str_contains($vectorDataHtml, 'd="M0 0L10 0 10 10Z"'), 'vector-data-renders-command-blob-path');
$assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-painted-fallback"') && str_contains($vectorDataHtml, '<rect x="0" y="0" width="12" height="6" fill="#0000ff"/>'), 'vector-data-painted-network-fallback-rect');
$assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-simple-rect-network"') && str_contains($vectorDataHtml, 'd="M0 0L12 0 12 6 0 6Z"') && str_contains($vectorDataHtml, 'fill="#0080ff"'), 'vector-data-simple-rect-network-renders-bounded-path');
$assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-closed-rect-network"') && str_contains($vectorDataHtml, 'd="M0 0L12 0 12 6 0 6Z"') && str_contains($vectorDataHtml, 'fill="#336699"'), 'vector-data-closed-rect-network-renders-bounded-path');
$assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-non-rect-network"') && str_contains($vectorDataHtml, 'data-figma-unsupported-vector="true"'), 'vector-data-non-rect-network-keeps-placeholder');
$assert(in_array('unsupported_vector_network_blob', $vectorDataDiagnosticCodes, true), 'vector-data-malformed-network-diagnostic');
$assert(1 === ($vectorNetworkDiagnostic['context']['byte_length'] ?? null) && 'ff' === ($vectorNetworkDiagnostic['context']['signature_hex'] ?? null), 'vector-network-diagnostic-context');
$vectorDataPlaceholderDiagnostics = $vectorDataResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
$malformedNetworkPlaceholder = null;
foreach ( $vectorDataPlaceholderDiagnostics['placeholder_nodes'] ?? array() as $placeholderNode ) {
    if ( is_array($placeholderNode) && 'vector:data-malformed' === ($placeholderNode['node_id'] ?? null) ) {
        $malformedNetworkPlaceholder = $placeholderNode;
        break;
    }
}
$assert('unsupported_vector_network_blob' === ($malformedNetworkPlaceholder['reason'] ?? null), 'vector-network-placeholder-reason');
$assert(array('vectorData.vectorNetworkBlob') === ($malformedNetworkPlaceholder['source_fields'] ?? null), 'vector-network-placeholder-source-field');
$assert(1 === ($vectorDataPlaceholderDiagnostics['placeholder_reasons']['unsupported_vector_network_blob'] ?? null), 'vector-network-placeholder-reason-count');
$vectorNetworkDiagnostics = array_values(array_filter(
    $vectorDataResult['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'unsupported_vector_network_blob' === ($diagnostic['code'] ?? null)
));
$assert(2 === count($vectorNetworkDiagnostics), 'vector-network-repeated-diagnostics-compacted');
$assert(2 === ($vectorNetworkDiagnostic['context']['occurrence_count'] ?? null), 'vector-network-diagnostic-occurrence-count');
$assert(2 === ($vectorNetworkDiagnostic['context']['affected_node_count'] ?? null), 'vector-network-diagnostic-affected-node-count');
$assert(array('vector:data-malformed', 'vector:data-painted-fallback') === ($vectorNetworkDiagnostic['context']['sample_node_ids'] ?? null), 'vector-network-diagnostic-sample-nodes');
$assert(array('1') === ($vectorNetworkDiagnostic['context']['sample_blob_refs'] ?? null), 'vector-network-diagnostic-sample-blob-refs');

// General vectorNetwork decode: 3 vertices, 3 segments (one carrying bezier
// tangents), one NONZERO region. Stride is 24 bytes (tangent-bearing), so the
// blob is rejected by the legacy exact-match decoders and handled by the new
// general decoder, emitting a real cubic-curve path rather than a placeholder.
$curvedNetworkVertices = array(array(0.0, 0.0), array(10.0, 0.0), array(10.0, 10.0));
$curvedNetworkSegments = array(
    array('start' => 0, 'end' => 1, 'ts' => array(0.0, 0.0), 'te' => array(0.0, 0.0)),
    array('start' => 1, 'end' => 2, 'ts' => array(2.0, 0.0), 'te' => array(0.0, -2.0)),
    array('start' => 2, 'end' => 0, 'ts' => array(0.0, 0.0), 'te' => array(0.0, 0.0)),
);
$curvedNetworkEntries = array(array(0, 0), array(1, 0), array(2, 0));
$curvedNetworkBlob = pack('V3', count($curvedNetworkVertices), count($curvedNetworkSegments), 1);
foreach ( $curvedNetworkVertices as $point ) {
    $curvedNetworkBlob .= pack('V', 0) . pack('g', $point[0]) . pack('g', $point[1]) . pack('V2', 0, 0);
}
foreach ( $curvedNetworkSegments as $segment ) {
    $curvedNetworkBlob .= pack('V', $segment['start']) . pack('g', $segment['ts'][0]) . pack('g', $segment['ts'][1])
        . pack('V', $segment['end']) . pack('g', $segment['te'][0]) . pack('g', $segment['te'][1]);
}
$curvedNetworkBlob .= pack('V3', count($curvedNetworkEntries), 0, 0);
foreach ( $curvedNetworkEntries as $entry ) {
    $curvedNetworkBlob .= pack('V2', $entry[0], $entry[1]);
}

$curvedNetworkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Curved Vector Network Fixture',
    'blobs' => array(array('bytes' => $curvedNetworkBlob)),
    'nodes' => array(
        array(
            'id'         => 'vector:curved-network',
            'type'       => 'VECTOR',
            'name'       => 'Curved Network',
            'width'      => 10,
            'height'     => 10,
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3))),
            'vectorData' => array('vectorNetworkBlob' => 0),
        ),
    ),
));
$curvedNetworkHtml = $fileContent($curvedNetworkResult, 'index.html');
$curvedNetworkVectors = $curvedNetworkResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
$curvedNetworkSummary = $curvedNetworkResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary'] ?? array();
$assert(str_contains($curvedNetworkHtml, 'data-figma-node-id="vector:curved-network"') && str_contains($curvedNetworkHtml, 'data-figma-vector="true"'), 'curved-network-renders-svg');
$assert(str_contains($curvedNetworkHtml, 'd="M0 0L10 0C12 0 10 8 10 10L0 0Z"'), 'curved-network-renders-cubic-path');
$assert(! str_contains($curvedNetworkHtml, 'data-figma-unsupported-vector="true"'), 'curved-network-not-placeholder');
$assert(1 === (int) ($curvedNetworkVectors['rendered_paths'] ?? 0), 'curved-network-counted-rendered');
$assert(0 === (int) ($curvedNetworkVectors['placeholders'] ?? 0), 'curved-network-no-placeholder-count');
$assert(1 === (int) ($curvedNetworkVectors['vector_network_decoded'] ?? 0), 'curved-network-network-decoded-count');
$assert(1 === (int) ($curvedNetworkVectors['decode_coverage']['vector_network_decoded'] ?? 0), 'curved-network-coverage-network-decoded');
$assert(1 === (int) ($curvedNetworkSummary['vector_network_decoded'] ?? 0), 'curved-network-summary-network-decoded');
// Summary rollup reflects rendered vectors instead of only externalized SVG files.
$assert(1 === (int) ($curvedNetworkSummary['generated_svg_count'] ?? -1), 'curved-network-summary-generated-svg-count');
$assert(1 === (int) ($curvedNetworkSummary['vector_decoded_to_svg'] ?? -1), 'curved-network-summary-decoded-to-svg');
$assert(0 === (int) ($curvedNetworkSummary['externalized_svg_asset_count'] ?? -1), 'curved-network-summary-externalized-count');

// Boolean operation: compose two child vector paths into one SVG. The default
// (UNION) overlays both child paths; the parent is no longer a placeholder.
$booleanOperationResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Boolean Operation Fixture',
    'nodes' => array(
        array(
            'id'       => 'bool:union',
            'type'     => 'BOOLEAN_OPERATION',
            'name'     => 'Union Icon',
            'width'    => 20,
            'height'   => 20,
            'children' => array(
                array(
                    'id'         => 'bool:child-a',
                    'type'       => 'VECTOR',
                    'name'       => 'A',
                    'width'      => 10,
                    'height'     => 10,
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
                    'pathData'   => 'M0 0L10 0L10 10L0 10Z',
                ),
                array(
                    'id'         => 'bool:child-b',
                    'type'       => 'VECTOR',
                    'name'       => 'B',
                    'width'      => 10,
                    'height'     => 10,
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1))),
                    'pathData'   => 'M5 5L15 5L15 15L5 15Z',
                ),
            ),
        ),
    ),
));
$booleanOperationHtml = $fileContent($booleanOperationResult, 'index.html');
$booleanOperationVectors = $booleanOperationResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
$assert(str_contains($booleanOperationHtml, 'data-figma-boolean-operation="union"'), 'boolean-operation-marks-union');
$assert(str_contains($booleanOperationHtml, 'd="M0 0L10 0 10 10 0 10Z" fill="#ff0000"'), 'boolean-operation-includes-child-a-path');
$assert(str_contains($booleanOperationHtml, 'd="M5 5L15 5 15 15 5 15Z" fill="#0000ff"'), 'boolean-operation-includes-child-b-path');
$assert(! str_contains($booleanOperationHtml, 'data-figma-unsupported-vector="true"'), 'boolean-operation-not-placeholder');
$assert(1 === (int) ($booleanOperationVectors['boolean_operations_composed'] ?? 0), 'boolean-operation-composed-count');
$assert(1 === (int) ($booleanOperationVectors['rendered_paths'] ?? 0), 'boolean-operation-rendered-count');
$assert(0 === (int) ($booleanOperationVectors['placeholders'] ?? 0), 'boolean-operation-no-placeholder');

// Boolean SUBTRACT over children sharing the operation origin approximates
// hole-cutting with a single fill-rule:evenodd path.
$booleanSubtractResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Boolean Subtract Fixture',
    'nodes' => array(
        array(
            'id'              => 'bool:subtract',
            'type'            => 'BOOLEAN_OPERATION',
            'name'            => 'Subtract Icon',
            'width'           => 20,
            'height'          => 20,
            'booleanOperation' => 'SUBTRACT',
            'fillPaints'      => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
            'children'        => array(
                array('id' => 'bool:outer', 'type' => 'VECTOR', 'name' => 'Outer', 'width' => 20, 'height' => 20, 'pathData' => 'M0 0L20 0L20 20L0 20Z'),
                array('id' => 'bool:inner', 'type' => 'VECTOR', 'name' => 'Inner', 'width' => 10, 'height' => 10, 'pathData' => 'M5 5L15 5L15 15L5 15Z'),
            ),
        ),
    ),
));
$booleanSubtractHtml = $fileContent($booleanSubtractResult, 'index.html');
$assert(str_contains($booleanSubtractHtml, 'data-figma-boolean-operation="subtract"'), 'boolean-subtract-marks-subtract');
$assert(str_contains($booleanSubtractHtml, 'd="M5 5L15 5 15 15 5 15Z M0 0L20 0 20 20 0 20Z" fill="#000000" fill-rule="evenodd"'), 'boolean-subtract-evenodd-composite');

$multiPageVectorPlaceholderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multi Page Vector Placeholder Fixture',
    'nodes' => array(
        array(
            'id'       => 'page:vector-placeholder',
            'type'     => 'CANVAS',
            'name'     => 'Vector Placeholder Pages',
            'children' => array(
                array('id' => 'frame:vector-home', 'type' => 'FRAME', 'name' => 'Vector Home', 'width' => 320, 'height' => 240, 'children' => array()),
                array('id' => 'frame:vector-about', 'type' => 'FRAME', 'name' => 'Vector About', 'width' => 320, 'height' => 240, 'children' => array(
                    array('id' => 'vector:multi-page-placeholder', 'type' => 'VECTOR', 'name' => 'Multi Page Placeholder', 'width' => 16, 'height' => 16, 'pathData' => 'M 0 0' . str_repeat(' L 1 1', 4000) . ' Z'),
                )),
            ),
        ),
    ),
), array(
    'multi_page' => true,
    'frame_ids' => array('frame:vector-home', 'frame:vector-about'),
    'entry_frame_id' => 'frame:vector-home',
));
$multiPageVectorPlaceholderDiagnostics = $multiPageVectorPlaceholderResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
$assert(1 === ($multiPageVectorPlaceholderDiagnostics['placeholder_reasons']['oversized_path_data'] ?? null), 'multi-page-vector-placeholder-reason-aggregated');

$nonRectVectorNetworkDiagnostic = null;
foreach ( $vectorNetworkDiagnostics as $diagnostic ) {
    if ( array(4, 4, 1) === ($diagnostic['context']['network_counts'] ?? null) ) {
        $nonRectVectorNetworkDiagnostic = $diagnostic;
        break;
    }
}
$assert(true === ($nonRectVectorNetworkDiagnostic['context']['single_region_loop_candidate'] ?? null), 'vector-network-single-region-candidate-diagnostic');
$assert(array('vertex_stride' => 20, 'segment_stride' => 16, 'region_bytes' => 44) === ($nonRectVectorNetworkDiagnostic['context']['candidate_layout'] ?? null), 'vector-network-candidate-layout-diagnostic');
$assert(array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)) === ($nonRectVectorNetworkDiagnostic['context']['candidate_vertex_points_sample'] ?? null), 'vector-network-candidate-point-sample');
$assert('Decode only after segment endpoints and region winding/order are validated as one closed non-branching loop.' === ($nonRectVectorNetworkDiagnostic['context']['candidate_decoder_requirement'] ?? null), 'vector-network-candidate-requirement');

$loopDecoderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Vector Network Loop Decoder Fixture',
    'blobs' => array(
        array('bytes' => $singleLoopNetworkBlob(
            array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
            array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)),
            array(array(0, 0), array(1, 0), array(2, 0), array(3, 0))
        )),
        array('bytes' => $singleLoopNetworkBlob(
            array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
            array(array(0, 1), array(1, 2), array(1, 3), array(3, 0)),
            array(array(0, 0), array(1, 0), array(2, 0), array(3, 0))
        )),
        array('bytes' => $singleLoopNetworkBlob(
            array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
            array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)),
            array(array(0, 0), array(1, 0), array(2, 0), array(3, 1))
        )),
        array('bytes' => $singleLoopNetworkBlob(
            array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
            array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)),
            array(array(0, 0), array(1, 0), array(2, 0), array(3, 0)),
            3
        )),
    ),
    'nodes' => array(
        array('id' => 'vector:loop-supported', 'type' => 'VECTOR', 'name' => 'Supported Loop', 'width' => 12, 'height' => 6, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3))), 'vectorData' => array('vectorNetworkBlob' => 0)),
        array('id' => 'vector:loop-branch', 'type' => 'VECTOR', 'name' => 'Branched Loop', 'width' => 12, 'height' => 6, 'vectorData' => array('vectorNetworkBlob' => 1)),
        array('id' => 'vector:loop-open-order', 'type' => 'VECTOR', 'name' => 'Open Region Order', 'width' => 12, 'height' => 6, 'vectorData' => array('vectorNetworkBlob' => 2)),
        array('id' => 'vector:loop-malformed-region', 'type' => 'VECTOR', 'name' => 'Malformed Region', 'width' => 12, 'height' => 6, 'vectorData' => array('vectorNetworkBlob' => 3)),
    ),
));
$loopDecoderHtml = $fileContent($loopDecoderResult, 'index.html');
$loopDecoderDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $loopDecoderResult['diagnostics'] ?? array()
);
$assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-supported"') && str_contains($loopDecoderHtml, 'd="M0 0L12 0 8 6 0 6Z"') && str_contains($loopDecoderHtml, 'fill="#1a334d"'), 'vector-network-single-loop-renders-path');
$assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-branch"') && str_contains($loopDecoderHtml, 'data-figma-unsupported-vector="true"'), 'vector-network-branch-keeps-placeholder');
$assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-open-order"') && str_contains($loopDecoderHtml, 'data-figma-unsupported-vector="true"'), 'vector-network-open-order-keeps-placeholder');
$assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-malformed-region"') && str_contains($loopDecoderHtml, 'data-figma-unsupported-vector="true"'), 'vector-network-malformed-region-keeps-placeholder');
$assert(in_array('unsupported_vector_network_blob', $loopDecoderDiagnosticCodes, true), 'vector-network-unsupported-loop-topology-diagnostic');

$zeroHeightSeparatorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Zero Height Separator Fixture',
    'blobs' => array(array('bytes' => "\xff")),
    'nodes' => array(
        array(
            'id'           => 'vector:zero-height-separator',
            'type'         => 'VECTOR',
            'name'         => 'Wide Zero Height Separator',
            'width'        => 1004,
            'height'       => 0,
            'strokeWeight' => 4,
            'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.75, 'g' => 0.75, 'b' => 0.75))),
            'vectorData'   => array('vectorNetworkBlob' => 0),
        ),
    ),
));
$zeroHeightSeparatorHtml = $fileContent($zeroHeightSeparatorResult, 'index.html');
$zeroHeightSeparatorCss = $fileContent($zeroHeightSeparatorResult, 'style.css');
$zeroHeightSeparatorDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $zeroHeightSeparatorResult['diagnostics'] ?? array()
);
$assert(str_contains($zeroHeightSeparatorHtml, 'data-figma-node-id="vector:zero-height-separator"') && str_contains($zeroHeightSeparatorHtml, 'data-figma-vector="true"'), 'zero-height-separator-renders-vector');
$assert(str_contains($zeroHeightSeparatorHtml, '<line x1="0" y1="2" x2="1004" y2="2" stroke="#bfbfbf" stroke-width="4"/>'), 'zero-height-separator-renders-line');
$assert(! str_contains($zeroHeightSeparatorHtml, 'data-figma-unsupported-vector="true"'), 'zero-height-separator-not-placeholder');
$assert(str_contains($zeroHeightSeparatorCss, '.figma-node-vector-zero-height-separator-wide-zero-height-separator{width:1004px;height:4px'), 'zero-height-separator-css-bounded-height');
$assert(in_array('unsupported_vector_network_blob', $zeroHeightSeparatorDiagnosticCodes, true), 'zero-height-separator-keeps-network-diagnostic');

$nearZeroContainerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Near Zero Container Fixture',
    'nodes' => array(
        array(
            'id'                => 'near-zero:container',
            'type'              => 'FRAME',
            'name'              => 'Decorative zero-height wrapper',
            'width'             => 600,
            'height'            => 0.0002,
            'layoutMode'        => 'VERTICAL',
            'relativeTransform' => array(
                array(0.8, -0.6, 0),
                array(0.6, 0.8, 0),
            ),
            'children'          => array(
                array(
                    'id'     => 'near-zero:child',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Decorative child',
                    'width'  => 600,
                    'height' => 320,
                ),
            ),
        ),
    ),
));
$nearZeroContainerCss = $fileContent($nearZeroContainerResult, 'style.css');
$assert(str_contains($nearZeroContainerCss, '.figma-node-near-zero-container-decorative-zero-height-wrapper{width:600px;height:0px;position:relative;display:flex;flex-direction:column}'), 'near-zero-container-keeps-zero-height-layout');
$assert(! str_contains($nearZeroContainerCss, '.figma-node-near-zero-container-decorative-zero-height-wrapper{width:600px;height:0px;position:relative;transform:'), 'near-zero-container-suppresses-transform-bounds-inflation');
$assert(str_contains($nearZeroContainerCss, '.figma-node-near-zero-child-decorative-child{width:600px;height:320px;position:absolute;flex-shrink:0}'), 'near-zero-container-keeps-child-rendering');

$agenticChevronLeftPrefix = hex2bin('0600000006000000010000000000000000000041000080410000000000000000');
$agenticChevronRightPrefix = hex2bin('06000000060000000100000000000000f4fdb43f0000804100000000be9f1641');
$agenticChevronWrongCountsPrefix = hex2bin('0600000005000000010000000000000000000041000080410000000000000000');
$agenticChevronUnknownPrefix = hex2bin('060000000600000001000000ffffffff00000041000080410000000000000000');
$agenticChevronResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Agentic Chevron Fixture',
    'blobs' => array(
        array('bytes' => str_pad(false === $agenticChevronLeftPrefix ? '' : $agenticChevronLeftPrefix, 288, "\0")),
        array('bytes' => str_pad(false === $agenticChevronRightPrefix ? '' : $agenticChevronRightPrefix, 288, "\0")),
        array('bytes' => str_pad(false === $agenticChevronLeftPrefix ? '' : $agenticChevronLeftPrefix, 287, "\0")),
        array('bytes' => str_pad(false === $agenticChevronWrongCountsPrefix ? '' : $agenticChevronWrongCountsPrefix, 288, "\0")),
        array('bytes' => str_pad(false === $agenticChevronUnknownPrefix ? '' : $agenticChevronUnknownPrefix, 288, "\0")),
    ),
    'nodes' => array(
        array('id' => 'chevron:left', 'type' => 'VECTOR', 'name' => 'Gridicon / gridicons-chevron-left', 'width' => 10.414, 'height' => 17, 'vectorData' => array('vectorNetworkBlob' => 0)),
        array('id' => 'chevron:right', 'type' => 'VECTOR', 'name' => 'Gridicon / gridicons-chevron-right', 'width' => 10.414, 'height' => 17, 'vectorData' => array('vectorNetworkBlob' => 1)),
        array('id' => 'chevron:bad-length', 'type' => 'VECTOR', 'name' => 'Bad chevron length', 'width' => 10, 'height' => 10, 'vectorData' => array('vectorNetworkBlob' => 2)),
        array('id' => 'chevron:bad-counts', 'type' => 'VECTOR', 'name' => 'Bad chevron counts', 'width' => 10, 'height' => 10, 'vectorData' => array('vectorNetworkBlob' => 3)),
        array('id' => 'chevron:unknown-signature', 'type' => 'VECTOR', 'name' => 'Unknown chevron signature', 'width' => 10, 'height' => 10, 'vectorData' => array('vectorNetworkBlob' => 4)),
    ),
));
$agenticChevronHtml = $fileContent($agenticChevronResult, 'index.html');
$agenticChevronDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $agenticChevronResult['diagnostics'] ?? array()
);
$assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:left"') && str_contains($agenticChevronHtml, 'M8 16L0 8 8 0 9.414 1.414 2.828 8 9.414 14.586 8 16Z'), 'agentic-chevron-left-renders');
$assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:right"') && str_contains($agenticChevronHtml, 'M1.414 16L9.414 8 1.414 0 0 1.414 6.586 8 0 14.586 1.414 16Z'), 'agentic-chevron-right-renders');
$assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:bad-length"') && str_contains($agenticChevronHtml, 'data-figma-unsupported-vector="true"'), 'agentic-chevron-bad-length-placeholder');
$assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:bad-counts"') && str_contains($agenticChevronHtml, 'data-figma-unsupported-vector="true"'), 'agentic-chevron-bad-counts-placeholder');
$assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:unknown-signature"') && str_contains($agenticChevronHtml, 'data-figma-unsupported-vector="true"'), 'agentic-chevron-unknown-signature-placeholder');
$assert(in_array('unsupported_vector_network_blob', $agenticChevronDiagnosticCodes, true), 'agentic-chevron-guarded-failures-diagnosed');

$vectorChildFallbackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Vector Child Fallback Fixture',
    'blobs' => array(array('bytes' => "\xff")),
    'nodes' => array(
        array(
            'id'         => 'boolean:child-fallback',
            'type'       => 'BOOLEAN_OPERATION',
            'name'       => 'Boolean With Child Fallback',
            'width'      => 20,
            'height'     => 20,
            'vectorData' => array('vectorNetworkBlob' => 0),
            'children'   => array(
                array(
                    'id'         => 'boolean:child-fallback-ellipse',
                    'type'       => 'ELLIPSE',
                    'name'       => 'Fallback Ellipse',
                    'width'      => 20,
                    'height'     => 20,
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1))),
                ),
            ),
        ),
    ),
));
$vectorChildFallbackHtml = $fileContent($vectorChildFallbackResult, 'index.html');
$vectorChildFallbackDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $vectorChildFallbackResult['diagnostics'] ?? array()
);
$assert(str_contains($vectorChildFallbackHtml, 'data-figma-node-id="boolean:child-fallback"'), 'vector-child-fallback-parent-renders');
$assert(str_contains($vectorChildFallbackHtml, 'data-figma-node-id="boolean:child-fallback-ellipse"') && str_contains($vectorChildFallbackHtml, '<ellipse '), 'vector-child-fallback-child-renders');
$assert(! str_contains($vectorChildFallbackHtml, 'data-figma-unsupported-vector="true"'), 'vector-child-fallback-not-placeholder');
$assert(in_array('unsupported_vector_network_blob', $vectorChildFallbackDiagnosticCodes, true), 'vector-child-fallback-network-diagnostic-kept');
$assert(! in_array('unsupported_vector_node_placeholder', $vectorChildFallbackDiagnosticCodes, true), 'vector-child-fallback-no-placeholder-diagnostic');

$matrixTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Matrix Transform Fixture',
    'nodes' => array(
        array(
            'id'        => 'matrix:flip',
            'type'      => 'FRAME',
            'name'      => 'Matrix Flip',
            'width'     => 20,
            'height'    => 20,
            'transform' => array('m00' => -1, 'm01' => 0, 'm02' => 0, 'm10' => 0, 'm11' => 1, 'm12' => 0),
        ),
    ),
));
$matrixTransformCss = $fileContent($matrixTransformResult, 'style.css');
$assert(str_contains($matrixTransformCss, 'transform:matrix(-1,0,0,1,0,0);transform-origin:0 0'), 'matrix-transform-uses-figma-origin');
$assetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $result['assets'] ?? array());
$assert(in_array('assets/hero-image.svg', $assetPaths, true), 'asset-report-path');
$assert(in_array('external_asset_omitted', $diagnosticCodes, true), 'external-asset-diagnostic');
$assert(in_array('scenegraph_node_id_duplicate', $diagnosticCodes, true), 'duplicate-node-diagnostic');
$assert(($result['files'] ?? array()) === ($sameResult['files'] ?? array()), 'deterministic-files');
$assert('blocks-engine/figma-transformer/parity-report/v1' === ($result['parity']['schema'] ?? null), 'parity-schema');
$assert('not_run' === ($result['parity']['status'] ?? null), 'parity-default-not-run');

$layoutMismatchBuilder = new \Automattic\BlocksEngine\FigmaTransformer\Diagnostics\LayoutMismatchReportBuilder();
$footerLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'footer', 'name' => 'Footer', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 880, 'width' => 1200, 'height' => 120)),
            array('id' => 'footer:text', 'parent_id' => 'footer', 'name' => 'Footer legal text', 'type' => 'TEXT', 'rect' => array('x' => 40, 'y' => 920, 'width' => 220, 'height' => 24)),
        ),
    ),
    array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'boxes' => array(
            array('node_id' => 'footer', 'rect' => array('x' => 0, 'y' => 880, 'width' => 1200, 'height' => 120)),
            array('node_id' => 'footer:text', 'selector' => '[data-figma-node-id="footer:text"]', 'rect' => array('x' => 40, 'y' => 1800, 'width' => 220, 'height' => 24)),
        ),
    ),
    array('threshold' => 24)
);
$footerLayoutMismatchCodes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $footerLayoutMismatch['diagnostics'] ?? array());
$assert('blocks-engine/figma-transformer/layout-mismatch-report/v1' === ($footerLayoutMismatch['schema'] ?? null), 'layout-mismatch-schema');
$assert('homeboy/static-artifact-dom-boxes/v1' === ($footerLayoutMismatch['input_schema'] ?? null), 'layout-mismatch-input-schema');
$assert('fail' === ($footerLayoutMismatch['status'] ?? null), 'layout-mismatch-fail-status');
$assert(in_array('misplaced_element', $footerLayoutMismatchCodes, true), 'layout-mismatch-misplaced-element');
$assert(in_array('element_outside_parent_bounds', $footerLayoutMismatchCodes, true), 'layout-mismatch-outside-parent');
$assert(880.0 === ($footerLayoutMismatch['diagnostics'][0]['delta']['y'] ?? null), 'layout-mismatch-delta-y');

$homeboyDomLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'card', 'name' => 'Card', 'type' => 'FRAME', 'rect' => array('x' => 320, 'y' => 120, 'width' => 300, 'height' => 180)),
        ),
    ),
    array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'entrypoints' => array(
            array(
                'page_path' => '/index.html',
                'elements' => array(
                    array('node_id' => 'card', 'selector' => '[data-figma-node-id="card"]', 'boundingClientRect' => array('x' => 8, 'y' => 120, 'width' => 1424, 'height' => 180)),
                ),
            ),
        ),
    ),
    array('threshold' => 24)
);
$homeboyDomLayoutMismatchCodes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $homeboyDomLayoutMismatch['diagnostics'] ?? array());
$homeboyDomMisplaced = null;
foreach ( $homeboyDomLayoutMismatch['diagnostics'] ?? array() as $diagnostic ) {
    if ( is_array($diagnostic) && 'misplaced_element' === ($diagnostic['code'] ?? null) ) {
        $homeboyDomMisplaced = $diagnostic;
        break;
    }
}
$assert('fail' === ($homeboyDomLayoutMismatch['status'] ?? null), 'layout-mismatch-homeboy-dom-status');
$assert(in_array('element_size_mismatch', $homeboyDomLayoutMismatchCodes, true), 'layout-mismatch-homeboy-dom-size-code');
$assert(-312.0 === ($homeboyDomMisplaced['delta']['x'] ?? null), 'layout-mismatch-homeboy-dom-x-delta');

$sourceAuthoredOverflowLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'overflow:parent', 'name' => 'Overflow Parent', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 120)),
            array('id' => 'overflow:text', 'parent_id' => 'overflow:parent', 'name' => 'Long text', 'type' => 'TEXT', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 180)),
        ),
    ),
    array(
        'boxes' => array(
            array('node_id' => 'overflow:parent', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 120)),
            array('node_id' => 'overflow:text', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 180)),
        ),
    ),
    array('threshold' => 24)
);
$assert('pass' === ($sourceAuthoredOverflowLayoutMismatch['status'] ?? null), 'layout-mismatch-source-authored-overflow-pass');

$worsenedOverflowLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'overflow:worse:parent', 'name' => 'Overflow Parent', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 120)),
            array('id' => 'overflow:worse:text', 'parent_id' => 'overflow:worse:parent', 'name' => 'Long text', 'type' => 'TEXT', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 180)),
        ),
    ),
    array(
        'boxes' => array(
            array('node_id' => 'overflow:worse:parent', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 120)),
            array('node_id' => 'overflow:worse:text', 'rect' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 220)),
        ),
    ),
    array('threshold' => 24)
);
$worsenedOverflowCodes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $worsenedOverflowLayoutMismatch['diagnostics'] ?? array());
$worsenedOutsideParent = null;
foreach ( $worsenedOverflowLayoutMismatch['diagnostics'] ?? array() as $diagnostic ) {
    if ( is_array($diagnostic) && 'element_outside_parent_bounds' === ($diagnostic['code'] ?? null) ) {
        $worsenedOutsideParent = $diagnostic;
        break;
    }
}
$assert('fail' === ($worsenedOverflowLayoutMismatch['status'] ?? null), 'layout-mismatch-worsened-overflow-fail');
$assert(in_array('element_outside_parent_bounds', $worsenedOverflowCodes, true), 'layout-mismatch-worsened-overflow-code');
$assert(60.0 === ($worsenedOutsideParent['parent']['source_overflow']['bottom'] ?? null), 'layout-mismatch-worsened-source-overflow');
$worsenedOverflowCauses = array_map(static fn (array $cause): string => (string) ($cause['cause'] ?? ''), $worsenedOverflowLayoutMismatch['summary']['suspected_causes'] ?? array());
$assert(in_array('source-overflow', $worsenedOverflowCauses, true), 'layout-mismatch-source-overflow-suspected-cause');

$clusteredLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'cluster:root', 'name' => 'Cluster Root', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300)),
            array('id' => 'cluster:item:1', 'parent_id' => 'cluster:root', 'name' => 'Item 1', 'type' => 'TEXT', 'rect' => array('x' => 20, 'y' => 40, 'width' => 120, 'height' => 20)),
            array('id' => 'cluster:item:2', 'parent_id' => 'cluster:root', 'name' => 'Item 2', 'type' => 'TEXT', 'rect' => array('x' => 20, 'y' => 80, 'width' => 120, 'height' => 20)),
        ),
    ),
    array(
        'boxes' => array(
            array('node_id' => 'cluster:root', 'rect' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300)),
            array('node_id' => 'cluster:item:1', 'rect' => array('x' => 52, 'y' => 88, 'width' => 120, 'height' => 20)),
            array('node_id' => 'cluster:item:2', 'rect' => array('x' => 52, 'y' => 128, 'width' => 120, 'height' => 20)),
        ),
    ),
    array('threshold' => 24)
);
$clusterSummary = $clusteredLayoutMismatch['summary']['clusters'] ?? array();
$assert(2 === ($clusterSummary['parent_delta'][0]['count'] ?? null), 'layout-mismatch-parent-delta-cluster-count');
$assert('cluster:root' === ($clusterSummary['parent_delta'][0]['parent_id'] ?? null), 'layout-mismatch-parent-delta-cluster-parent');
$assert(2 === ($clusterSummary['repeated_position_delta'][0]['count'] ?? null), 'layout-mismatch-repeated-delta-cluster-count');
$assert(array('x' => 32, 'y' => 48) === ($clusterSummary['repeated_position_delta'][0]['delta'] ?? null), 'layout-mismatch-repeated-delta-cluster-values');
$assert('item #' === ($clusterSummary['node_pattern'][0]['name_pattern'] ?? null), 'layout-mismatch-node-pattern-normalized-name');
$clusteredCauses = array_map(static fn (array $cause): string => (string) ($cause['cause'] ?? ''), $clusteredLayoutMismatch['summary']['suspected_causes'] ?? array());
$assert(in_array('absolute-offset', $clusteredCauses, true), 'layout-mismatch-absolute-offset-suspected-cause');

$typedLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'typed:root', 'name' => 'Full bleed root', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 0, 'width' => 640, 'height' => 400)),
            array('id' => 'typed:text', 'parent_id' => 'typed:root', 'name' => 'Title', 'type' => 'TEXT', 'rect' => array('x' => 20, 'y' => 20, 'width' => 200, 'height' => 24)),
            array('id' => 'typed:icon', 'parent_id' => 'typed:root', 'name' => 'Chevron icon', 'type' => 'VECTOR', 'rect' => array('x' => 20, 'y' => 70, 'width' => 16, 'height' => 16)),
        ),
    ),
    array(
        'boxes' => array(
            array('node_id' => 'typed:root', 'rect' => array('x' => 0, 'y' => 0, 'width' => 800, 'height' => 500)),
            array('node_id' => 'typed:text', 'rect' => array('x' => 20, 'y' => 20, 'width' => 200, 'height' => 70)),
            array('node_id' => 'typed:icon', 'rect' => array('x' => 20, 'y' => 70, 'width' => 48, 'height' => 48)),
        ),
    ),
    array('threshold' => 24)
);
$typedCauses = array_map(static fn (array $cause): string => (string) ($cause['cause'] ?? ''), $typedLayoutMismatch['summary']['suspected_causes'] ?? array());
$assert(in_array('root-fill', $typedCauses, true), 'layout-mismatch-root-fill-suspected-cause');
$assert(in_array('text-height', $typedCauses, true), 'layout-mismatch-text-height-suspected-cause');
$assert(in_array('icon/vector-bounds', $typedCauses, true), 'layout-mismatch-vector-bounds-suspected-cause');

$genericCauseLayoutMismatch = $layoutMismatchBuilder->build(
    array(
        'visual_node_map' => array(
            array('id' => 'generic:parent', 'name' => 'Shifted parent', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 0, 'width' => 120, 'height' => 80)),
            array('id' => 'generic:child', 'parent_id' => 'generic:parent', 'name' => 'Shifted child', 'type' => 'RECTANGLE', 'rect' => array('x' => 10, 'y' => 10, 'width' => 30, 'height' => 20)),
            array('id' => 'generic:zero', 'name' => 'Zero width shell', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 100, 'width' => 0, 'height' => 80)),
            array('id' => 'generic:clip-parent', 'name' => 'Clip parent', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 220, 'width' => 100, 'height' => 80)),
            array('id' => 'generic:clip-child', 'parent_id' => 'generic:clip-parent', 'name' => 'Generated clipped child', 'type' => 'RECTANGLE', 'rect' => array('x' => 10, 'y' => 230, 'width' => 20, 'height' => 20)),
            array('id' => 'generic:vector', 'name' => 'Decorative vector', 'type' => 'VECTOR', 'rect' => array('x' => 0, 'y' => 340, 'width' => 24, 'height' => 24)),
        ),
    ),
    array(
        'boxes' => array(
            array('node_id' => 'generic:parent', 'rect' => array('x' => 40, 'y' => 60, 'width' => 120, 'height' => 80)),
            array('node_id' => 'generic:child', 'rect' => array('x' => 50, 'y' => 70, 'width' => 30, 'height' => 20)),
            array('node_id' => 'generic:zero', 'rect' => array('x' => 0, 'y' => 100, 'width' => 40, 'height' => 80)),
            array('node_id' => 'generic:clip-parent', 'rect' => array('x' => 0, 'y' => 220, 'width' => 100, 'height' => 80)),
            array('node_id' => 'generic:clip-child', 'rect' => array('x' => 150, 'y' => 230, 'width' => 20, 'height' => 20)),
            array('node_id' => 'generic:vector', 'rect' => array('x' => 48, 'y' => 340, 'width' => 24, 'height' => 24)),
        ),
    ),
    array('threshold' => 24)
);
$genericCauses = array_map(static fn (array $cause): string => (string) ($cause['cause'] ?? ''), $genericCauseLayoutMismatch['summary']['suspected_causes'] ?? array());
$assert(in_array('same-size-position-shift', $genericCauses, true), 'layout-mismatch-same-size-position-shift-suspected-cause');
$assert(in_array('parent-visual-map-mismatch', $genericCauses, true), 'layout-mismatch-parent-visual-map-suspected-cause');
$assert(in_array('zero-size-source-box', $genericCauses, true), 'layout-mismatch-zero-size-source-box-suspected-cause');
$assert(in_array('generated-vs-source-clipping', $genericCauses, true), 'layout-mismatch-generated-vs-source-clipping-suspected-cause');
$assert(in_array('vector-shell-wrapper-offset', $genericCauses, true), 'layout-mismatch-vector-shell-wrapper-offset-suspected-cause');
blocks_engine_figma_transformer_run_layout_mismatch_contract($assert);
blocks_engine_figma_transformer_run_render_style_mismatch_contract($assert);
blocks_engine_figma_transformer_run_geometry_box_contract($assert);

$layoutMismatchTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name' => 'Layout Mismatch Fixture',
    'nodes' => array(
        array(
            'id' => 'frame:layout-mismatch',
            'type' => 'FRAME',
            'name' => 'Layout Mismatch Page',
            'width' => 1200,
            'height' => 1000,
            'children' => array(
                array('id' => 'footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 1200, 'height' => 120, 'transform' => array('m02' => 0, 'm12' => 880)),
                array('id' => 'footer:text', 'type' => 'TEXT', 'name' => 'Footer legal text', 'characters' => 'Privacy and terms', 'width' => 220, 'height' => 24, 'transform' => array('m02' => 40, 'm12' => 920)),
            ),
        ),
    ),
), array(
    'generated_dom_boxes' => array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'boxes' => array(
            array('node_id' => 'footer', 'rect' => array('x' => 0, 'y' => 880, 'width' => 1200, 'height' => 120)),
            array('node_id' => 'footer:text', 'rect' => array('x' => 40, 'y' => 1800, 'width' => 220, 'height' => 24)),
        ),
    ),
    'layout_mismatch_threshold' => 24,
));
$layoutMismatchTransformDiagnostics = $layoutMismatchTransformResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$layoutMismatchArtifactQualityCodes = array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), $layoutMismatchTransformDiagnostics['artifact_quality']['signals'] ?? array());
$assert(0 < ($layoutMismatchTransformDiagnostics['layout']['layout_mismatch_count'] ?? 0), 'layout-mismatch-transform-count');
$assert('fail' === ($layoutMismatchTransformDiagnostics['layout']['layout_mismatch_status'] ?? null), 'layout-mismatch-transform-status');
$assert(in_array('layout_mismatch', $layoutMismatchArtifactQualityCodes, true), 'layout-mismatch-artifact-quality-signal');
$assert(! empty($layoutMismatchTransformDiagnostics['layout']['layout_mismatch']['summary']['clusters']['parent_delta']), 'layout-mismatch-transform-parent-clusters');

$layoutNotEvaluatedResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name' => 'Layout Not Evaluated Fixture',
    'nodes' => array(
        array('id' => 'layout:not-evaluated', 'type' => 'FRAME', 'name' => 'Simple Page', 'width' => 720, 'height' => 320),
    ),
));
$layoutNotEvaluatedQuality = $layoutNotEvaluatedResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary'] ?? array();
$assert(0 === ($layoutNotEvaluatedQuality['layout_mismatch_count'] ?? null), 'layout-not-evaluated-count-zero');
$assert('not_evaluated' === ($layoutNotEvaluatedQuality['layout_mismatch_status'] ?? null), 'layout-not-evaluated-status');

$unusedAssetResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Unused Asset Fixture',
    'assets' => array(
        'used-image' => array('mime_type' => 'image/png', 'content' => 'used image'),
        'unused-image' => array('mime_type' => 'image/png', 'content' => 'unused image'),
    ),
    'nodes'  => array(
        array(
            'id'         => 'asset:used',
            'type'       => 'RECTANGLE',
            'name'       => 'Used image',
            'width'      => 10,
            'height'     => 10,
            'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'used-image')),
        ),
    ),
));
$unusedAssetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $unusedAssetResult['assets'] ?? array());
$assert(1 === ($unusedAssetResult['metrics']['asset_count'] ?? null), 'unused-asset-filtered-count');
$assert(in_array('assets/used-image.png', $unusedAssetPaths, true), 'unused-asset-keeps-referenced');
$assert(! in_array('assets/unused-image.png', $unusedAssetPaths, true), 'unused-asset-omits-unreferenced');

$backgroundPaintsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Background Paints Fixture',
    'nodes' => array(
        array(
            'id'               => 'background:paints',
            'type'             => 'FRAME',
            'name'             => 'Background Paints',
            'width'            => 10,
            'height'           => 10,
            'backgroundPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 1, 'b' => 0, 'a' => 1))),
        ),
    ),
));
$backgroundPaintsCss = $fileContent($backgroundPaintsResult, 'style.css');
$assert(str_contains($backgroundPaintsCss, '.figma-node-background-paints-background-paints{width:10px;height:10px;background:#00ff00}'), 'background-paints-emits-background');

$frameInspection = blocks_engine_figma_transformer_inspect_frames_scenegraph(array(
    'nodes' => array(
        array(
            'id' => 'page:1',
            'type' => 'CANVAS',
            'name' => 'Page One',
            'children' => array(
                array(
                    'id' => 'section:1',
                    'type' => 'SECTION',
                    'name' => 'Marketing Pages',
                    'width' => 2000,
                    'height' => 1600,
                    'children' => array(
                        array(
                            'id' => 'frame:home',
                            'type' => 'FRAME',
                            'name' => 'Home Page',
                            'width' => 1440,
                            'height' => 1200,
                            'children' => array(
                                array('id' => 'text:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Hello'),
                                array('id' => 'image:hero', 'type' => 'RECTANGLE', 'name' => 'Hero image', 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'hero'))),
                            ),
                        ),
                        array(
                            'id' => 'frame:home-mobile',
                            'type' => 'FRAME',
                            'name' => 'Home Page Mobile',
                            'width' => 390,
                            'height' => 1200,
                            'children' => array(
                                array('id' => 'text:hero-mobile', 'type' => 'TEXT', 'name' => 'Hero Mobile', 'characters' => 'Hello'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
), array('frame_inspection_limit' => 3));
$frameInspectionHome = null;
foreach ( $frameInspection['candidates'] ?? array() as $candidate ) {
    if ( is_array($candidate) && 'frame:home' === ($candidate['id'] ?? null) ) {
        $frameInspectionHome = $candidate;
        break;
    }
}
$assert('blocks-engine/figma-transformer/frame-inspection/v1' === ($frameInspection['schema'] ?? null), 'frame-inspection-schema');
$assert(3 === ($frameInspection['returned_count'] ?? null), 'frame-inspection-limit');
$assert('Page One' === ($frameInspectionHome['page']['name'] ?? null), 'frame-inspection-page-ancestor');
$assert('Marketing Pages' === ($frameInspectionHome['section']['name'] ?? null), 'frame-inspection-section-ancestor');
$assert(1 === ($frameInspectionHome['text_count'] ?? null), 'frame-inspection-text-count');
$assert(1 === ($frameInspectionHome['asset_reference_count'] ?? null), 'frame-inspection-asset-count');
$assert('desktop' === ($frameInspectionHome['device_hint'] ?? null), 'frame-inspection-desktop-device-hint');
$assert('frame:home-mobile' === ($frameInspectionHome['responsive_siblings'][0]['id'] ?? null), 'frame-inspection-responsive-sibling-id');
$assert('mobile' === ($frameInspectionHome['responsive_siblings'][0]['device_hint'] ?? null), 'frame-inspection-responsive-sibling-device-hint');

$responsivePagePlanSource = array(
    'nodes' => array(
        array(
            'id'       => 'page:responsive',
            'type'     => 'CANVAS',
            'name'     => 'Responsive Pages',
            'children' => array(
                array(
                    'id'       => 'section:responsive',
                    'type'     => 'SECTION',
                    'name'     => 'Marketing',
                    'width'    => 4000,
                    'height'   => 5000,
                    'children' => array(
                        array(
                            'id'       => 'frame:home-desktop',
                            'type'     => 'FRAME',
                            'name'     => 'Home Page Desktop',
                            'width'    => 1440,
                            'height'   => 3200,
                            'children' => array(
                                array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                            ),
                        ),
                        array(
                            'id'       => 'frame:home-tablet',
                            'type'     => 'FRAME',
                            'name'     => 'Home Page Tablet',
                            'width'    => 834,
                            'height'   => 3200,
                            'children' => array(
                                array('id' => 'text:home-tablet', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                            ),
                        ),
                        array(
                            'id'       => 'frame:home-mobile',
                            'type'     => 'FRAME',
                            'name'     => 'Home Page Mobile',
                            'width'    => 390,
                            'height'   => 3200,
                            'children' => array(
                                array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                            ),
                        ),
                        array(
                            'id'       => 'frame:about-desktop',
                            'type'     => 'FRAME',
                            'name'     => 'About Page',
                            'width'    => 1440,
                            'height'   => 3000,
                            'children' => array(
                                array('id' => 'text:about', 'type' => 'TEXT', 'name' => 'About', 'characters' => 'About us'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
);
$responsivePagePlan = ( new ScenegraphPagePlanner() )->plan($responsivePagePlanSource, array('include_all_pages' => true));
$responsivePageByFrame = static function (array $plan, string $frameId): ?array {
    foreach ( $plan['pages'] ?? array() as $page ) {
        if ( is_array($page) && $frameId === ($page['frame_id'] ?? null) ) {
            return $page;
        }
    }

    return null;
};
$responsiveHomePage = $responsivePageByFrame($responsivePagePlan, 'frame:home-desktop');
$responsiveAboutPage = $responsivePageByFrame($responsivePagePlan, 'frame:about-desktop');
$assert(4 === ($responsivePagePlan['candidate_count'] ?? null), 'page-plan-responsive-candidate-count');
$assert(2 === ($responsivePagePlan['page_count'] ?? null), 'page-plan-responsive-collapses-to-two-pages');
$assert(null !== $responsiveHomePage, 'page-plan-responsive-home-primary-is-desktop');
$assert(true === ($responsiveHomePage['responsive'] ?? null), 'page-plan-responsive-home-flagged-responsive');
$assert(3 === ($responsiveHomePage['breakpoint_count'] ?? null), 'page-plan-responsive-home-three-breakpoints');
// A responsive page's slug reflects the PAGE, not its widest variant: the
// breakpoint token ("Desktop") is stripped so desktop+mobile collapse to one
// stable slug.
$assert('home-page' === ($responsiveHomePage['slug'] ?? null), 'page-plan-responsive-slug-strips-breakpoint-token');
$assert(array('frame:home-desktop', 'frame:home-tablet', 'frame:home-mobile')
    === array_map(static fn (array $variant): string => (string) ($variant['frame_id'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variants-ordered-widest-first');
$assert(array('desktop', 'tablet', 'mobile')
    === array_map(static fn (array $variant): string => (string) ($variant['device_hint'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variant-device-hints');
$assert(true === ($responsiveHomePage['variants'][0]['primary'] ?? null)
    && false === ($responsiveHomePage['variants'][1]['primary'] ?? null)
    && false === ($responsiveHomePage['variants'][2]['primary'] ?? null), 'page-plan-responsive-only-widest-is-primary');
$assert(1440.0 === ($responsiveHomePage['variants'][0]['viewport_width'] ?? null)
    && 390.0 === ($responsiveHomePage['variants'][2]['viewport_width'] ?? null), 'page-plan-responsive-variant-viewport-widths');
$assert(null !== $responsiveAboutPage, 'page-plan-responsive-about-stays-its-own-page');
$assert(false === ($responsiveAboutPage['responsive'] ?? null) && 1 === ($responsiveAboutPage['breakpoint_count'] ?? null), 'page-plan-non-responsive-frame-single-variant');
$assert(1 === count($responsiveAboutPage['variants'] ?? array()) && 'frame:about-desktop' === ($responsiveAboutPage['variants'][0]['frame_id'] ?? null), 'page-plan-non-responsive-frame-self-variant');

// RESPONSIVE EMISSION (#247 item 2): StaticHtmlEmitter::emitSite consumes a
// page plan's `variants[]` and renders the primary (widest) variant as the base
// layout, then emits `@media (max-width: …)` blocks carrying ONLY the per-node
// style declarations that differ at each narrower breakpoint. A single-variant
// page emits no `@media` at all. Variant frames are matched onto the base frame
// by structural position so the overrides key on the base class names.
$responsiveEmitFrame = static function (string $id, string $name, float $width, float $height, array $children): array {
    return array(
        'id'         => $id,
        'type'       => 'FRAME',
        'name'       => $name,
        'box'        => array('width' => $width, 'height' => $height),
        'background' => '#ffffff',
        'children'   => $children,
    );
};
$responsiveEmitCard = static function (string $id, string $name, float $width, float $height, string $background): array {
    return array(
        'id'         => $id,
        'type'       => 'RECTANGLE',
        'name'       => $name,
        'box'        => array('width' => $width, 'height' => $height),
        'background' => $background,
    );
};
$responsiveEmitScenegraph = array(
    'name'  => 'Responsive Emission Site',
    'nodes' => array(
        $responsiveEmitFrame('frame:home-desktop', 'Home Desktop', 1440.0, 3000.0, array(
            $responsiveEmitCard('card:desktop', 'Hero Card', 1200.0, 400.0, '#ff0000'),
        )),
        $responsiveEmitFrame('frame:home-tablet', 'Home Tablet', 834.0, 3000.0, array(
            $responsiveEmitCard('card:tablet', 'Hero Card', 700.0, 400.0, '#ff0000'),
        )),
        $responsiveEmitFrame('frame:home-mobile', 'Home Mobile', 390.0, 3200.0, array(
            $responsiveEmitCard('card:mobile', 'Hero Card', 350.0, 500.0, '#00ff00'),
        )),
        $responsiveEmitFrame('frame:about', 'About', 1440.0, 2000.0, array(
            $responsiveEmitCard('card:about', 'About Card', 1100.0, 300.0, '#0000ff'),
        )),
    ),
);
$responsiveEmitPagePlan = array(
    'pages' => array(
        array(
            'frame_id'         => 'frame:home-desktop',
            'name'             => 'Home',
            'path'             => 'index.html',
            'entrypoint'       => true,
            'responsive'       => true,
            'breakpoint_count' => 3,
            'variants'         => array(
                array('frame_id' => 'frame:home-desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                array('frame_id' => 'frame:home-tablet', 'device_hint' => 'tablet', 'viewport_width' => 834.0, 'primary' => false, 'order' => 1),
                array('frame_id' => 'frame:home-mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false, 'order' => 2),
            ),
        ),
        array(
            'frame_id'         => 'frame:about',
            'name'             => 'About',
            'path'             => 'about.html',
            'entrypoint'       => false,
            'responsive'       => false,
            'breakpoint_count' => 1,
            'variants'         => array(
                array('frame_id' => 'frame:about', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
            ),
        ),
    ),
);
$responsiveEmitResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveEmitScenegraph, $responsiveEmitPagePlan);
$responsiveEmitCss = '';
foreach ( $responsiveEmitResult['files'] ?? array() as $responsiveEmitFile ) {
    if ( is_array($responsiveEmitFile) && 'style.css' === ($responsiveEmitFile['path'] ?? null) ) {
        $responsiveEmitCss = (string) ($responsiveEmitFile['content'] ?? '');
    }
}
$assert('success' === ($responsiveEmitResult['status'] ?? null), 'responsive-emit-status-success');
$assert('' !== $responsiveEmitCss, 'responsive-emit-stylesheet-present');
// Two narrower breakpoints => exactly two media blocks, keyed at the MIDPOINT
// between adjacent variant widths (not the narrow variant's own width).
// desktop=1440, tablet=834, mobile=390:
//   tablet breakpoint = round((1440+834)/2) = 1137
//   mobile breakpoint = round((834+390)/2)  = 612
$assert(2 === substr_count($responsiveEmitCss, '@media'), 'responsive-emit-two-media-blocks');
$assert(str_contains($responsiveEmitCss, '@media (max-width:1137px){'), 'responsive-emit-tablet-media-query');
$assert(str_contains($responsiveEmitCss, '@media (max-width:612px){'), 'responsive-emit-mobile-media-query');
// Base layout uses the primary (desktop) variant styles, emitted before media.
$responsiveEmitBasePos = strpos($responsiveEmitCss, '.figma-node-card-desktop-hero-card{width:1200px;height:400px;background:#ff0000}');
$assert(false !== $responsiveEmitBasePos, 'responsive-emit-base-uses-primary-variant');
$responsiveEmitFirstMediaPos = strpos($responsiveEmitCss, '@media');
$assert(false !== $responsiveEmitFirstMediaPos && $responsiveEmitBasePos < $responsiveEmitFirstMediaPos, 'responsive-emit-base-precedes-media');
// Narrower-wins cascade: tablet block precedes mobile block.
$assert(strpos($responsiveEmitCss, '@media (max-width:1137px)') < strpos($responsiveEmitCss, '@media (max-width:612px)'), 'responsive-emit-cascade-widest-first');
// Media blocks override on the BASE class names, carrying only changed props.
$responsiveEmitTabletBlock = substr($responsiveEmitCss, strpos($responsiveEmitCss, '@media (max-width:1137px)'), strpos($responsiveEmitCss, '@media (max-width:612px)') - strpos($responsiveEmitCss, '@media (max-width:1137px)'));
$assert(str_contains($responsiveEmitTabletBlock, '.figma-node-card-desktop-hero-card{width:700px}'), 'responsive-emit-tablet-card-width-diff-only');
$assert(! str_contains($responsiveEmitTabletBlock, 'background:'), 'responsive-emit-tablet-omits-unchanged-background');
$responsiveEmitMobileBlock = substr($responsiveEmitCss, strpos($responsiveEmitCss, '@media (max-width:612px)'));
$assert(str_contains($responsiveEmitMobileBlock, '.figma-node-card-desktop-hero-card{width:350px;height:500px;background:#00ff00}'), 'responsive-emit-mobile-card-diffs-width-height-background');
// The single-variant About page contributes NO media override for its nodes.
$assert(0 === preg_match('/@media[^@]*figma-node-card-about/s', $responsiveEmitCss), 'responsive-emit-single-variant-page-no-media');

// SINGLE-VARIANT PAGE PARITY: a page plan with only primary variants emits the
// SAME CSS as today — zero `@media` queries.
$singleVariantPagePlan = array(
    'pages' => array(
        array(
            'frame_id'         => 'frame:about',
            'name'             => 'About',
            'path'             => 'index.html',
            'entrypoint'       => true,
            'responsive'       => false,
            'breakpoint_count' => 1,
            'variants'         => array(
                array('frame_id' => 'frame:about', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
            ),
        ),
    ),
);
$singleVariantResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveEmitScenegraph, $singleVariantPagePlan);
$singleVariantCss = '';
foreach ( $singleVariantResult['files'] ?? array() as $singleVariantFile ) {
    if ( is_array($singleVariantFile) && 'style.css' === ($singleVariantFile['path'] ?? null) ) {
        $singleVariantCss = (string) ($singleVariantFile['content'] ?? '');
    }
}
$assert('' !== $singleVariantCss, 'responsive-emit-single-variant-stylesheet-present');
$assert(! str_contains($singleVariantCss, '@media'), 'responsive-emit-single-variant-no-media-query');

$planDiagnosticByCode = static function (array $plan, string $code): ?array {
    foreach ( $plan['diagnostics'] ?? array() as $diagnostic ) {
        if ( is_array($diagnostic) && $code === ($diagnostic['code'] ?? null) ) {
            return $diagnostic;
        }
    }

    return null;
};

// (b) A genuine desktop/tablet/mobile group STILL collapses AND records its
// grouping rationale (distinct device hints).
$responsiveGroupDiagnostic = $planDiagnosticByCode($responsivePagePlan, 'responsive_group_formed');
$assert(null !== $responsiveGroupDiagnostic, 'page-plan-responsive-group-rationale-emitted');
$assert(in_array('device_hint_diversity', $responsiveGroupDiagnostic['reasons'] ?? array(), true), 'page-plan-responsive-group-rationale-device-diversity');
$assert('frame:home-desktop' === ($responsiveGroupDiagnostic['primary_frame_id'] ?? null), 'page-plan-responsive-group-rationale-primary');

// (a) FALSE-POSITIVE GUARD: four same-name, same-device-hint (desktop),
// same-width (1440) frames differing only in height are duplicate/iteration
// drafts (the real "For Hosts" data finding), NOT responsive breakpoints. They
// must stay as separate pages and surface a duplicate_draft_frames diagnostic.
$duplicateDraftSource = array(
    'nodes' => array(
        array(
            'id'       => 'page:hosts',
            'type'     => 'CANVAS',
            'name'     => 'Hosts Pages',
            'children' => array(
                array(
                    'id'       => 'section:hosts',
                    'type'     => 'SECTION',
                    'name'     => 'Marketing',
                    'width'    => 4000,
                    'height'   => 40000,
                    'children' => array(
                        array(
                            'id'       => 'frame:hosts-a',
                            'type'     => 'FRAME',
                            'name'     => 'For Hosts',
                            'width'    => 1440,
                            'height'   => 9777,
                            'children' => array(array('id' => 'text:hosts-a', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                        ),
                        array(
                            'id'       => 'frame:hosts-b',
                            'type'     => 'FRAME',
                            'name'     => 'For Hosts',
                            'width'    => 1440,
                            'height'   => 8613,
                            'children' => array(array('id' => 'text:hosts-b', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                        ),
                        array(
                            'id'       => 'frame:hosts-c',
                            'type'     => 'FRAME',
                            'name'     => 'For Hosts',
                            'width'    => 1440,
                            'height'   => 9188,
                            'children' => array(array('id' => 'text:hosts-c', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                        ),
                        array(
                            'id'       => 'frame:hosts-d',
                            'type'     => 'FRAME',
                            'name'     => 'For Hosts',
                            'width'    => 1440,
                            'height'   => 9000,
                            'children' => array(array('id' => 'text:hosts-d', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                        ),
                    ),
                ),
            ),
        ),
    ),
);
$duplicateDraftPlan = ( new ScenegraphPagePlanner() )->plan($duplicateDraftSource, array('include_all_pages' => true));
$assert(4 === ($duplicateDraftPlan['page_count'] ?? null), 'page-plan-duplicate-drafts-stay-separate-pages');
$duplicateDraftResponsive = false;
foreach ( $duplicateDraftPlan['pages'] ?? array() as $duplicatePage ) {
    if ( is_array($duplicatePage) && true === ($duplicatePage['responsive'] ?? null) ) {
        $duplicateDraftResponsive = true;
    }
}
$assert(false === $duplicateDraftResponsive, 'page-plan-duplicate-drafts-not-responsive');
$duplicateDraftDiagnostic = $planDiagnosticByCode($duplicateDraftPlan, 'duplicate_draft_frames');
$assert(null !== $duplicateDraftDiagnostic, 'page-plan-duplicate-drafts-diagnostic-emitted');
$assert('desktop' === ($duplicateDraftDiagnostic['device_hint'] ?? null), 'page-plan-duplicate-drafts-diagnostic-device-hint');
$assert(4 === count($duplicateDraftDiagnostic['frame_ids'] ?? array()), 'page-plan-duplicate-drafts-diagnostic-frame-count');
$assert(null === $planDiagnosticByCode($duplicateDraftPlan, 'responsive_group_formed'), 'page-plan-duplicate-drafts-not-grouped');

// (c) FRAME-CANDIDATE BOUND: detection now scales with the number of FRAME
// candidates, not total node count. The `responsive_detection_bounded`
// diagnostic only fires in pathological cases (here forced via a frame-limit of
// 1 against 4 frames). When bounded, detection is skipped — no second index is
// built — and frames fall back to one-page-per-frame.
$boundedPlan = ( new ScenegraphPagePlanner() )->plan(
    $responsivePagePlanSource,
    array('include_all_pages' => true, 'responsive_detection_frame_limit' => 1)
);
$boundedDiagnostic = $planDiagnosticByCode($boundedPlan, 'responsive_detection_bounded');
$assert(null !== $boundedDiagnostic, 'page-plan-bounded-detection-diagnostic-emitted');
$assert(1 === ($boundedDiagnostic['frame_candidate_limit'] ?? null), 'page-plan-bounded-detection-frame-limit');
$assert(4 === ($boundedDiagnostic['frame_candidate_count'] ?? null), 'page-plan-bounded-detection-frame-count');
$assert(4 === ($boundedPlan['page_count'] ?? null), 'page-plan-bounded-detection-one-page-per-frame');
$boundedHomePage = $responsivePageByFrame($boundedPlan, 'frame:home-desktop');
$assert(null !== $boundedHomePage && false === ($boundedHomePage['responsive'] ?? null), 'page-plan-bounded-detection-no-collapse');

// (c2) SCALE: a design whose descendant node count is WELL ABOVE the old 25k
// ceiling — but with only a handful of FRAME candidates — must STILL run
// detection and form a genuine desktop/tablet/mobile responsive group, with NO
// `responsive_detection_bounded` skip. This proves grouping stays ON at
// "Automattic scale" and that detection reads frame-level data, not all nodes.
$largeDescendantCount = 26000; // > the retired RESPONSIVE_DETECTION_NODE_LIMIT of 25000.
$bulkChildren = static function (string $prefix, int $count): array {
    $children = array();
    for ( $i = 0; $i < $count; $i++ ) {
        $children[] = array(
            'id'         => $prefix . ':text:' . $i,
            'type'       => 'TEXT',
            'name'       => 'Body ' . $i,
            'characters' => 'Lorem ipsum dolor sit amet number ' . $i,
        );
    }

    return $children;
};
$scaleSource = array(
    'nodes' => array(
        array(
            'id'       => 'page:scale',
            'type'     => 'CANVAS',
            'name'     => 'Scale Pages',
            'children' => array(
                array(
                    'id'       => 'section:scale',
                    'type'     => 'SECTION',
                    'name'     => 'Marketing',
                    'width'    => 4000,
                    'height'   => 40000,
                    'children' => array(
                        array(
                            'id'       => 'frame:landing-desktop',
                            'type'     => 'FRAME',
                            'name'     => 'Landing Page Desktop',
                            'width'    => 1440,
                            'height'   => 9000,
                            // This single frame alone carries more descendants
                            // than the entire old 25k node ceiling.
                            'children' => $bulkChildren('desktop', $largeDescendantCount),
                        ),
                        array(
                            'id'       => 'frame:landing-tablet',
                            'type'     => 'FRAME',
                            'name'     => 'Landing Page Tablet',
                            'width'    => 834,
                            'height'   => 9000,
                            'children' => array(array('id' => 'tablet:text', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Lorem')),
                        ),
                        array(
                            'id'       => 'frame:landing-mobile',
                            'type'     => 'FRAME',
                            'name'     => 'Landing Page Mobile',
                            'width'    => 390,
                            'height'   => 9000,
                            'children' => array(array('id' => 'mobile:text', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Lorem')),
                        ),
                    ),
                ),
            ),
        ),
    ),
);
$scalePlan = ( new ScenegraphPagePlanner() )->plan($scaleSource, array('include_all_pages' => true));
$assert(null === $planDiagnosticByCode($scalePlan, 'responsive_detection_bounded'), 'page-plan-scale-detection-not-bounded');
$assert(3 === ($scalePlan['candidate_count'] ?? null), 'page-plan-scale-three-frame-candidates');
$assert(1 === ($scalePlan['page_count'] ?? null), 'page-plan-scale-collapses-to-one-page');
$scaleHomePage = $responsivePageByFrame($scalePlan, 'frame:landing-desktop');
$assert(null !== $scaleHomePage, 'page-plan-scale-primary-is-desktop');
$assert(true === ($scaleHomePage['responsive'] ?? null), 'page-plan-scale-flagged-responsive');
$assert(3 === ($scaleHomePage['breakpoint_count'] ?? null), 'page-plan-scale-three-breakpoints');
// The primary frame's own subtree exceeds the retired node ceiling, proving
// grouping is active at a scale that previously auto-disabled it.
$assert(($scaleHomePage['node_count'] ?? 0) > 25000, 'page-plan-scale-primary-above-old-ceiling');
$scaleGroupDiagnostic = $planDiagnosticByCode($scalePlan, 'responsive_group_formed');
$assert(null !== $scaleGroupDiagnostic, 'page-plan-scale-group-formed');
$assert(in_array('device_hint_diversity', $scaleGroupDiagnostic['reasons'] ?? array(), true), 'page-plan-scale-group-device-diversity');
$assert(array('desktop', 'tablet', 'mobile')
    === array_map(static fn (array $variant): string => (string) ($variant['device_hint'] ?? ''), $scaleHomePage['variants'] ?? array()), 'page-plan-scale-variant-device-hints');

// (c3) DETECTION IS FRAME-LEVEL: the lightweight detection path produces the
// full detection contract (device_hint / sibling_group_key /
// responsive_siblings) from frame-level records ALONE — no source, no
// ScenegraphIndex. This is the memory-efficient primitive the planner reuses.
$frameLevelDetection = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector() )->detectResponsiveFrames(array(
    array('id' => 'f:desktop', 'name' => 'Pricing Desktop', 'width' => 1440.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
    array('id' => 'f:tablet', 'name' => 'Pricing Tablet', 'width' => 834.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
    array('id' => 'f:mobile', 'name' => 'Pricing Mobile', 'width' => 390.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
));
$assert('desktop' === ($frameLevelDetection['f:desktop']['device_hint'] ?? null), 'frame-level-detection-desktop-hint');
$assert('mobile' === ($frameLevelDetection['f:mobile']['device_hint'] ?? null), 'frame-level-detection-mobile-hint');
$assert(isset($frameLevelDetection['f:desktop']['sibling_group_key']), 'frame-level-detection-sibling-group-key');
$frameLevelSiblingIds = array_map(
    static fn (array $sibling): string => (string) ($sibling['id'] ?? ''),
    $frameLevelDetection['f:desktop']['responsive_siblings'] ?? array()
);
$assert(in_array('f:tablet', $frameLevelSiblingIds, true) && in_array('f:mobile', $frameLevelSiblingIds, true), 'frame-level-detection-links-siblings');

$matrixWebsiteCandidate = array(
    'id'         => 'matrix:site:home',
    'type'       => 'FRAME',
    'name'       => 'Homepage',
    'page'       => array('name' => 'Website Pages'),
    'parent'     => array('type' => 'CANVAS'),
    'width'      => 1440,
    'height'     => 4200,
    'text_count' => 24,
    'score'      => 700,
);
$matrixResearchCandidate = array(
    'id'         => 'matrix:research:screen',
    'type'       => 'FRAME',
    'name'       => 'Desktop - 12',
    'page'       => array('name' => 'Research/Screens'),
    'parent'     => array('type' => 'CANVAS'),
    'width'      => 1440,
    'height'     => 4600,
    'text_count' => 40,
    'score'      => 820,
);
$matrixSelection = matrix_select_frame_ids(array('candidates' => array($matrixResearchCandidate, $matrixWebsiteCandidate)), 2);
$assert(array('matrix:site:home', 'matrix:research:screen') === $matrixSelection, 'fixture-matrix-research-screens-demoted');
$assert(matrix_candidate_rank($matrixWebsiteCandidate) > matrix_candidate_rank($matrixResearchCandidate), 'fixture-matrix-reference-page-rank-penalty');
$assert(! in_array('page_collection', matrix_candidate_selection_reasons($matrixResearchCandidate), true), 'fixture-matrix-reference-page-not-page-collection');

$multiPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Multi Page Fixture',
    'assets' => array(
        'home-image' => array('mime_type' => 'image/png', 'content' => 'home image'),
        'about-image' => array('mime_type' => 'image/png', 'content' => 'about image'),
        'unused-image' => array('mime_type' => 'image/png', 'content' => 'unused image'),
    ),
    'nodes'  => array(
        array(
            'id'       => 'page:multi',
            'type'     => 'CANVAS',
            'name'     => 'Site Pages',
            'children' => array(
                array(
                    'id'       => 'frame:home',
                    'type'     => 'FRAME',
                    'name'     => 'Home',
                    'width'    => 1440,
                    'height'   => 1200,
                    'children' => array(
                        array('id' => 'text:home', 'type' => 'TEXT', 'name' => 'Home title', 'characters' => 'Home Hero', 'fontName' => array('family' => 'Example Sans', 'style' => 'Regular'), 'fontSize' => 20),
                        array('id' => 'image:home', 'type' => 'RECTANGLE', 'name' => 'Home image', 'width' => 100, 'height' => 60, 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'home-image'))),
                        array('id' => 'vector:home-large', 'type' => 'VECTOR', 'name' => 'Home Large Vector', 'width' => 10, 'height' => 10, 'figma_vector_paths' => array(array('data' => $externalizedVectorPath, 'source' => 'strokeGeometry'))),
                    ),
                ),
                array(
                    'id'       => 'frame:about',
                    'type'     => 'FRAME',
                    'name'     => 'About',
                    'width'    => 1440,
                    'height'   => 900,
                    'children' => array(
                        array('id' => 'text:about', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About Hero', 'fontName' => array('family' => 'Example Sans', 'style' => 'Bold'), 'fontSize' => 20),
                        array('id' => 'image:about', 'type' => 'RECTANGLE', 'name' => 'About image', 'width' => 100, 'height' => 60, 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'about-image'))),
                    ),
                ),
            ),
        ),
    ),
), array(
    'multi_page' => true,
    'frame_ids' => array('frame:home', 'frame:about'),
    'entry_frame_id' => 'frame:home',
    'font_css' => '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}',
    'generated_render_evidence' => array(
        'schema' => 'homeboy/static-artifact-render-evidence/v1',
        'entrypoints' => array(
            array(
                'page_path' => 'index.html',
                'elements' => array(
                    array('node_id' => 'text:home', 'computed_style' => array('font-family' => 'Example Sans', 'font-size' => '20px', 'font-weight' => '400')),
                ),
            ),
            array(
                'page_path' => 'about.html',
                'elements' => array(
                    array('node_id' => 'text:about', 'computed_style' => array('font-family' => 'Arial, sans-serif', 'font-size' => '20px', 'font-weight' => '700')),
                ),
            ),
        ),
    ),
));
$multiPageIndex = $fileContent($multiPageResult, 'index.html');
$multiPageAbout = $fileContent($multiPageResult, 'about.html');
$multiPageStyle = $fileContent($multiPageResult, 'style.css');
$multiPageAssetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $multiPageResult['assets'] ?? array());
$assert('success' === ($multiPageResult['status'] ?? null), 'multi-page-transform-success');
$assert(str_contains($multiPageIndex, 'Home Hero'), 'multi-page-index-renders-entry-frame');
$assert(! str_contains($multiPageIndex, 'About Hero'), 'multi-page-index-omits-other-frame');
$assert(str_contains($multiPageAbout, 'About Hero'), 'multi-page-about-renders-second-frame');
$assert(str_contains($multiPageIndex, '<style data-figma-transformer-css="true">') && str_contains($multiPageIndex, '.figma-node-frame-home-home'), 'multi-page-index-inlines-page-css');
$assert(str_contains($multiPageStyle, '.figma-node-frame-home-home'), 'multi-page-shared-css-home');
$assert(str_contains($multiPageStyle, '.figma-node-frame-about-about'), 'multi-page-shared-css-about');
$assert(2 === ($multiPageResult['metrics']['page_count'] ?? null), 'multi-page-page-count');
$assert(2 === ($multiPageResult['source_reports']['compiled_site']['totals']['page_count'] ?? null), 'multi-page-compiled-site-page-count');
$assert('about.html' === ($multiPageResult['source_reports']['compiled_site']['pages'][1]['path'] ?? null), 'multi-page-compiled-site-page-path');
$assert(3 === ($multiPageResult['source_reports']['compiled_site']['totals']['asset_count'] ?? null), 'multi-page-compiled-site-asset-count');
$assert(array('Example Sans') === ($multiPageResult['source_reports']['figma']['html']['font_families'] ?? null), 'multi-page-font-families-aggregated');
$multiPageFontUsage = $multiPageResult['source_reports']['figma']['html']['font_usage'] ?? array();
$multiPageCompiledFontUsage = $multiPageResult['source_reports']['compiled_site']['theme']['font_usage'] ?? array();
$assert('Example Sans' === ($multiPageFontUsage[0]['family'] ?? null) && array(400, 700) === ($multiPageFontUsage[0]['weights'] ?? null), 'multi-page-font-usage-aggregated');
$assert(2 === ($multiPageFontUsage[0]['text_node_count'] ?? null), 'multi-page-font-usage-node-count-aggregated');
$assert('Example Sans' === ($multiPageCompiledFontUsage[0]['family'] ?? null) && array(400, 700) === ($multiPageCompiledFontUsage[0]['weights'] ?? null), 'multi-page-compiled-site-font-usage');
$assert(in_array('assets/home-image.png', $multiPageAssetPaths, true), 'multi-page-home-asset');
$assert(in_array('assets/about-image.png', $multiPageAssetPaths, true), 'multi-page-about-asset');
$assert(! in_array('assets/unused-image.png', $multiPageAssetPaths, true), 'multi-page-unused-asset-filtered');
$multiPageTransformDiagnostics = $multiPageResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert('blocks-engine/figma-transformer/transform-diagnostics/v1' === ($multiPageTransformDiagnostics['schema'] ?? null), 'multi-page-transform-diagnostics-schema');
$assert('multi_page' === ($multiPageTransformDiagnostics['scope'] ?? null), 'multi-page-transform-diagnostics-scope');
$assert(2 === count($multiPageTransformDiagnostics['pages'] ?? array()), 'multi-page-transform-diagnostics-pages');
$assert('about.html' === ($multiPageTransformDiagnostics['pages'][1]['page_path'] ?? null), 'multi-page-transform-diagnostics-page-path');
$assert(2 === ($multiPageTransformDiagnostics['images']['paint_refs'] ?? null), 'multi-page-transform-diagnostics-image-paints');
$assert(2 === ($multiPageTransformDiagnostics['images']['resolved_assets'] ?? null), 'multi-page-transform-diagnostics-resolved-assets');
$assert(3 === ($multiPageTransformDiagnostics['assets']['emitted_files'] ?? null), 'multi-page-transform-diagnostics-emitted-assets');
$assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-count');
$assert(($multiPageTransformDiagnostics['generated_svg_assets']['gzip_bytes'] ?? 0) > 0, 'multi-page-transform-diagnostics-generated-svg-gzip-bytes');
$assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['path_element_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-path-element-count');
$assert(($multiPageTransformDiagnostics['generated_svg_assets']['path_data_bytes'] ?? 0) > 65536, 'multi-page-transform-diagnostics-generated-svg-path-data-bytes');
$assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['unique_path_data_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-unique-path-data-count');
$assert(0 === ($multiPageTransformDiagnostics['generated_svg_assets']['duplicate_path_data_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-duplicate-path-data-count');
$assert('selected_frames' === ($multiPageTransformDiagnostics['selection']['mode'] ?? null), 'multi-page-transform-diagnostics-selection-mode');
$assert('frame:home' === ($multiPageTransformDiagnostics['selection']['selected_frames'][0]['frame_id'] ?? null), 'multi-page-transform-diagnostics-entry-frame-selection');
$assert('about.html' === ($multiPageTransformDiagnostics['selection']['selected_frames'][1]['path'] ?? null), 'multi-page-transform-diagnostics-about-selection-path');
$assert(1 === ($multiPageTransformDiagnostics['layout']['render_style_mismatch_count'] ?? null), 'multi-page-render-style-mismatch-aggregated');
$assert('fail' === ($multiPageTransformDiagnostics['layout']['render_style_mismatch_status'] ?? null), 'multi-page-render-style-status-aggregated');
$assert(1 === ($multiPageTransformDiagnostics['layout']['render_style']['summary']['font_mismatch_count'] ?? null), 'multi-page-render-style-font-count-aggregated');
$assert(in_array('render_style_mismatch', array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), $multiPageTransformDiagnostics['artifact_quality']['signals'] ?? array()), true), 'multi-page-render-style-artifact-quality-signal');
$assert('warn' === ($multiPageTransformDiagnostics['artifact_quality']['quality_status'] ?? null), 'multi-page-transform-diagnostics-quality-status-warn');

// RESPONSIVE PAGE ASSEMBLY — LIVE WIRING (#247): a source whose section holds
// "Home – Desktop" + "Home – Mobile" sibling frames must (1) PAIR into ONE
// responsive page in the planner (generic name-normalization + device hint +
// width signals, NOT hardcoded ids) and (2) flow that grouping through the LIVE
// transform so the emitted page carries the base (desktop) layout PLUS an
// `@media (max-width: …)` block for the mobile breakpoint. This exercises the
// full path end-to-end (planner grouping -> transformScenegraphPages ->
// transformResponsivePage -> StaticHtmlEmitter::emitSite -> merged style.css),
// proving emitSite is no longer dormant in production.
$responsiveLiveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Responsive Live Fixture',
    'nodes' => array(
        array(
            'id'       => 'page:responsive-live',
            'type'     => 'CANVAS',
            'name'     => 'Site',
            'children' => array(
                array(
                    'id'       => 'section:responsive-live',
                    'type'     => 'SECTION',
                    'name'     => 'Home Section',
                    'children' => array(
                        array(
                            'id'       => 'frame:home-desktop-live',
                            'type'     => 'FRAME',
                            'name'     => 'Home – Desktop',
                            'width'    => 1440,
                            'height'   => 3000,
                            'children' => array(
                                array('id' => 'card:home-desktop', 'type' => 'RECTANGLE', 'name' => 'Hero Card', 'width' => 1200, 'height' => 400, 'backgroundColor' => array('r' => 1.0, 'g' => 0.0, 'b' => 0.0, 'a' => 1.0)),
                                array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'Welcome home', 'fontSize' => 32),
                            ),
                        ),
                        array(
                            'id'       => 'frame:home-mobile-live',
                            'type'     => 'FRAME',
                            'name'     => 'Home – Mobile',
                            'width'    => 390,
                            'height'   => 3200,
                            'children' => array(
                                array('id' => 'card:home-mobile', 'type' => 'RECTANGLE', 'name' => 'Hero Card', 'width' => 350, 'height' => 500, 'backgroundColor' => array('r' => 0.0, 'g' => 1.0, 'b' => 0.0, 'a' => 1.0)),
                                array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'Welcome home', 'fontSize' => 32),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
), array(
    'multi_page'        => true,
    'include_all_pages' => true,
));
$responsiveLiveIndex = $fileContent($responsiveLiveResult, 'index.html');
$responsiveLiveStyle = $fileContent($responsiveLiveResult, 'style.css');
$assert(in_array($responsiveLiveResult['status'] ?? null, array('success', 'success_with_warnings'), true), 'responsive-live-transform-success');
// Desktop + mobile siblings paired into ONE page (not two).
$assert(1 === ($responsiveLiveResult['metrics']['page_count'] ?? null), 'responsive-live-single-page');
$assert('' !== $responsiveLiveIndex, 'responsive-live-index-emitted');
$assert('' !== $responsiveLiveStyle, 'responsive-live-stylesheet-emitted');
// Base styles present (the widest/desktop variant drives the base layout).
$assert(str_contains($responsiveLiveStyle, '.figma-root'), 'responsive-live-base-styles-present');
$assert(str_contains($responsiveLiveStyle, 'width:1200px') || str_contains($responsiveLiveStyle, 'width:1200px;'), 'responsive-live-base-uses-desktop-width');
// The merged stylesheet carries an `@media` block (the mobile breakpoint),
// proving the responsive emission fired through the LIVE transform path.
// desktop=1440, mobile=390: midpoint = round((1440+390)/2) = 915.
$assert(str_contains($responsiveLiveStyle, '@media'), 'responsive-live-media-block-present');
$assert(str_contains($responsiveLiveStyle, '@media (max-width:915px)'), 'responsive-live-mobile-media-query');
// `@media` block survived chunk merging intact (balanced braces, base before media).
$assert(strpos($responsiveLiveStyle, '.figma-root') < strpos($responsiveLiveStyle, '@media'), 'responsive-live-base-precedes-media');
$assert(substr_count($responsiveLiveStyle, '{') === substr_count($responsiveLiveStyle, '}'), 'responsive-live-balanced-braces');
$responsiveLivePagePlan = $responsiveLiveResult['source_reports']['figma']['pages'] ?? array();
$assert(true === ($responsiveLivePagePlan['pages'][0]['responsive'] ?? null), 'responsive-live-plan-flagged-responsive');
$assert(2 === ($responsiveLivePagePlan['pages'][0]['breakpoint_count'] ?? null), 'responsive-live-plan-two-breakpoints');

// SINGLE-FRAME PARITY: a source with one frame still produces ONE
// non-responsive page with NO `@media` block (the live wiring only fires for
// detected responsive variant-groups).
$singleFrameLiveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Single Frame Live Fixture',
    'nodes' => array(
        array(
            'id'       => 'page:single-live',
            'type'     => 'CANVAS',
            'name'     => 'Site',
            'children' => array(
                array(
                    'id'       => 'frame:about-live',
                    'type'     => 'FRAME',
                    'name'     => 'About',
                    'width'    => 1440,
                    'height'   => 2000,
                    'children' => array(
                        array('id' => 'card:about-live', 'type' => 'RECTANGLE', 'name' => 'About Card', 'width' => 1100, 'height' => 300, 'backgroundColor' => array('r' => 0.0, 'g' => 0.0, 'b' => 1.0, 'a' => 1.0)),
                        array('id' => 'text:about-live', 'type' => 'TEXT', 'name' => 'About copy', 'characters' => 'About us', 'fontSize' => 24),
                    ),
                ),
            ),
        ),
    ),
), array(
    'multi_page'        => true,
    'include_all_pages' => true,
));
$singleFrameLiveStyle = $fileContent($singleFrameLiveResult, 'style.css');
$assert(in_array($singleFrameLiveResult['status'] ?? null, array('success', 'success_with_warnings'), true), 'single-frame-live-transform-success');
$assert(1 === ($singleFrameLiveResult['metrics']['page_count'] ?? null), 'single-frame-live-single-page');
$assert('' !== $singleFrameLiveStyle, 'single-frame-live-stylesheet-emitted');
$assert(! str_contains($singleFrameLiveStyle, '@media'), 'single-frame-live-no-media-block');
$assert(false === ($singleFrameLiveResult['source_reports']['figma']['pages']['pages'][0]['responsive'] ?? true), 'single-frame-live-plan-not-responsive');

$imageScaleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Image Scale Fixture',
    'assets' => array(
        'fill-image'    => array('mime_type' => 'image/png', 'content' => 'fill image'),
        'stretch-image' => array('mime_type' => 'image/png', 'content' => 'stretch image'),
    ),
    'nodes'  => array(
        array(
            'id'         => 'scale:fill',
            'type'       => 'RECTANGLE',
            'name'       => 'Fill image',
            'width'      => 100,
            'height'     => 80,
            'fillPaints' => array(
                array('type' => 'IMAGE', 'imageRef' => 'fill-image', 'imageScaleMode' => 'FILL', 'imageShouldColorManage' => true, 'originalImageWidth' => 200, 'originalImageHeight' => 100),
            ),
        ),
        array(
            'id'         => 'scale:stretch',
            'type'       => 'RECTANGLE',
            'name'       => 'Stretch image',
            'width'      => 100,
            'height'     => 80,
            'fillPaints' => array(
                array('type' => 'IMAGE', 'imageRef' => 'stretch-image', 'imageScaleMode' => 'STRETCH'),
            ),
        ),
    ),
));
$imageScaleCss = $fileContent($imageScaleResult, 'style.css');
$assert(str_contains($imageScaleCss, '.figma-node-scale-fill-fill-image{width:100px;height:80px;background-image:url("assets/fill-image.png");background-size:cover;background-position:center}'), 'image-fill-emits-cover-background');
$assert(str_contains($imageScaleCss, '.figma-node-scale-stretch-stretch-image{width:100px;height:80px;background-image:url("assets/stretch-image.png");background-size:100% 100%;background-repeat:no-repeat;background-position:center}'), 'image-stretch-emits-stretch-background');
$imageScaleVisualNodes = $imageScaleResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
$imageScaleFillVisualNode = null;
foreach ( is_array($imageScaleVisualNodes) ? $imageScaleVisualNodes : array() as $visualNode ) {
    if ( is_array($visualNode) && 'scale:fill' === ($visualNode['id'] ?? null) ) {
        $imageScaleFillVisualNode = $visualNode;
        break;
    }
}
$assert('FILL' === ($imageScaleFillVisualNode['image']['scale_mode'] ?? null), 'visual-node-image-scale-mode');
$assert(true === ($imageScaleFillVisualNode['image']['color_managed'] ?? null), 'visual-node-image-color-managed');
$assert(200.0 === ($imageScaleFillVisualNode['image']['originalImageWidth'] ?? null), 'visual-node-image-original-width');

$imageTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Image Transform Fixture',
    'assets' => array(
        'crop-image' => array('mime_type' => 'image/png', 'content' => 'crop image'),
        'fill-crop'  => array('mime_type' => 'image/png', 'content' => 'fill image'),
    ),
    'nodes'  => array(
        array(
            'id'         => 'image:crop',
            'type'       => 'RECTANGLE',
            'name'       => 'Cropped image',
            'width'      => 100,
            'height'     => 80,
            'fillPaints' => array(
                array(
                    'type'           => 'IMAGE',
                    'imageRef'       => 'crop-image',
                    'imageScaleMode' => 'STRETCH',
                    'transform'      => array(
                        array(0.5, 0, 0.25),
                        array(0, 0.8, 0.1),
                    ),
                ),
            ),
        ),
        array(
            'id'         => 'image:fill-crop',
            'type'       => 'RECTANGLE',
            'name'       => 'Fill crop image',
            'width'      => 100,
            'height'     => 80,
            'fillPaints' => array(
                array(
                    'type'           => 'IMAGE',
                    'imageRef'       => 'fill-crop',
                    'imageScaleMode' => 'FILL',
                    'transform'      => array(
                        array(0.5, 0, 0.25),
                        array(0, 0.8, 0.1),
                    ),
                ),
            ),
        ),
    ),
));
$imageTransformCss = $fileContent($imageTransformResult, 'style.css');
$assert(str_contains($imageTransformCss, '.figma-node-image-crop-cropped-image{width:100px;height:80px;background-image:url("assets/crop-image.png");background-size:200px 100px;background-repeat:no-repeat;background-position:-50px -10px}'), 'image-stretch-transform-emits-crop-background');
$assert(str_contains($imageTransformCss, '.figma-node-image-fill-crop-fill-crop-image{width:100px;height:80px;background-image:url("assets/fill-crop.png");background-size:cover;background-position:center}'), 'image-fill-transform-keeps-cover-background');

$multilineTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multiline Text Fixture',
    'nodes' => array(
        array(
            'id'         => 'text:multiline',
            'type'       => 'TEXT',
            'name'       => 'Checklist Text',
            'characters' => "One\nTwo\nThree",
            'fontSize'   => 16,
        ),
    ),
));
$multilineTextCss = $fileContent($multilineTextResult, 'style.css');
$assert(str_contains($multilineTextCss, '.figma-node-text-multiline-checklist-text{font-size:16px;white-space:pre-line}'), 'multiline-text-preserves-line-breaks');

$derivedTextLayoutScenegraph = array(
    'name'  => 'Derived Text Layout Fixture',
    'blobs' => array(
        array('bytes' => $quadraticCommandBlob),
    ),
    'nodes' => array(
        array(
            'id'              => 'text:derived-layout',
            'type'            => 'TEXT',
            'name'            => 'Measured Text',
            'characters'      => 'A B',
            'width'           => 146.5,
            'height'          => 32.25,
            'fontSize'        => 10,
            'fontName'        => array('family' => 'Example Sans', 'style' => 'Regular'),
            'derivedTextData' => array(
                'layoutSize' => array('x' => 146.5, 'y' => 32.25),
                'baselines'  => array(
                    array(
                        'position'       => array('x' => 0, 'y' => 20),
                        'width'          => 140,
                        'lineY'          => 0,
                        'lineHeight'     => 22,
                        'lineAscent'     => 17,
                        'firstCharacter' => 0,
                        'endCharacter'   => 17,
                    ),
                ),
                'glyphs' => array(
                    array('firstCharacter' => 0, 'advance' => 0.5, 'fontSize' => 10, 'x' => 2, 'y' => 3, 'commandsBlob' => 0),
                    array('firstCharacter' => 1, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 0),
                    array('firstCharacter' => 2, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 0),
                ),
                'fontMetaData' => array(
                    array(
                        'key'            => array('family' => 'Example Sans', 'style' => 'Regular'),
                        'fontLineHeight' => 1.2,
                        'fontWeight'     => 400,
                    ),
                ),
            ),
        ),
    ),
);
$derivedTextLayoutResult = blocks_engine_figma_transformer_transform_scenegraph($derivedTextLayoutScenegraph);
$derivedTextVisualNodes = $derivedTextLayoutResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
$derivedTextVisualNode = null;
foreach ( is_array($derivedTextVisualNodes) ? $derivedTextVisualNodes : array() as $visualNode ) {
    if ( is_array($visualNode) && 'text:derived-layout' === ($visualNode['id'] ?? null) ) {
        $derivedTextVisualNode = $visualNode;
        break;
    }
}
$assert(true === ($derivedTextVisualNode['text']['has_derived_layout'] ?? null), 'visual-node-derived-text-layout-present');
$assert(1 === ($derivedTextVisualNode['text']['baseline_count'] ?? null), 'visual-node-derived-text-baseline-count');
$assert(3 === ($derivedTextVisualNode['text']['glyph_count'] ?? null), 'visual-node-derived-text-glyph-count');
$assert(146.5 === ($derivedTextVisualNode['text']['derived_layout']['size']['width'] ?? null), 'visual-node-derived-text-layout-width');
$assert('M 0 0 Q 4 8 8 0 Z' === ($derivedTextVisualNode['text']['derived_layout']['glyph_paths'][0]['data'] ?? null), 'visual-node-derived-text-glyph-quadratic-path');
$assert(2.0 === ($derivedTextVisualNode['text']['derived_layout']['glyph_paths'][0]['x'] ?? null), 'visual-node-derived-text-glyph-position');
$assert('dom_text' === ($derivedTextVisualNode['text']['glyph_rendering'] ?? null), 'visual-node-derived-text-default-dom-rendering');
$assert(false === ($derivedTextLayoutResult['source_reports']['figma']['html']['render_text_glyph_paths'] ?? null), 'derived-text-glyph-rendering-default-disabled');
$assert(! str_contains($fileContent($derivedTextLayoutResult, 'index.html'), 'data-figma-text-glyphs="true"'), 'derived-text-default-avoids-glyph-svg');

$unsupportedGlyphScenegraph = array(
    'name'  => 'Unsupported Glyph Diagnostic Fixture',
    'blobs' => array(array('bytes' => chr(9))),
    'nodes' => array(
        array(
            'id'              => 'text:unsupported-glyph-a',
            'type'            => 'TEXT',
            'characters'      => 'Unsupported glyph A',
            'derivedTextData' => array(
                'glyphs' => array(
                    array('firstCharacter' => 0, 'commandsBlob' => 0),
                    array('firstCharacter' => 1, 'commandsBlob' => 0),
                    array('firstCharacter' => 2, 'commandsBlob' => 0),
                ),
            ),
        ),
        array(
            'id'              => 'text:unsupported-glyph-b',
            'type'            => 'TEXT',
            'characters'      => 'Unsupported glyph B',
            'derivedTextData' => array(
                'glyphs' => array(
                    array('firstCharacter' => 0, 'commandsBlob' => 0),
                    array('firstCharacter' => 1, 'commandsBlob' => 0),
                ),
            ),
        ),
    ),
);
$unsupportedGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($unsupportedGlyphScenegraph);
$unsupportedGlyphDiagnostics = array_values(array_filter(
    $unsupportedGlyphResult['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'unsupported_text_glyph_command_blob' === ($diagnostic['code'] ?? null)
));
$assert(1 === count($unsupportedGlyphDiagnostics), 'unsupported-glyph-diagnostics-bounded');
$assert(5 === ($unsupportedGlyphDiagnostics[0]['context']['total_count'] ?? null), 'unsupported-glyph-diagnostics-total-count');
$assert(2 === ($unsupportedGlyphDiagnostics[0]['context']['affected_node_count'] ?? null), 'unsupported-glyph-diagnostics-node-count');
$assert(array('text:unsupported-glyph-a', 'text:unsupported-glyph-b') === ($unsupportedGlyphDiagnostics[0]['context']['sample_node_ids'] ?? null), 'unsupported-glyph-diagnostics-sample-node-ids');
$assert(str_contains($fileContent($unsupportedGlyphResult, 'index.html'), 'Unsupported glyph A'), 'unsupported-glyph-diagnostics-preserve-text-a');
$assert(str_contains($fileContent($unsupportedGlyphResult, 'index.html'), 'Unsupported glyph B'), 'unsupported-glyph-diagnostics-preserve-text-b');

// Whitespace glyphs are emitted by Figma as a single 0x00 (empty-path) command
// blob: well-formed, but carrying no drawable outline. These are valid, not
// unsupported, and must NOT raise unsupported_text_glyph_command_blob warnings
// (the FSE Pilot footer text "Proudly powered by WordPress.com" produced
// thousands of these false-positive warnings before this gate).
$emptyGlyphCommandBlob = chr(0);
$whitespaceGlyphScenegraph = array(
    'name'  => 'Whitespace Glyph Empty-Path Fixture',
    'blobs' => array(
        array('bytes' => $quadraticCommandBlob),
        array('bytes' => $emptyGlyphCommandBlob),
    ),
    'nodes' => array(
        array(
            'id'              => 'text:whitespace-glyph',
            'type'            => 'TEXT',
            'name'            => 'Measured Whitespace Text',
            'characters'      => 'A B',
            'width'           => 146.5,
            'height'          => 32.25,
            'fontSize'        => 10,
            'fontName'        => array('family' => 'Example Sans', 'style' => 'Regular'),
            'derivedTextData' => array(
                'layoutSize' => array('x' => 146.5, 'y' => 32.25),
                'glyphs' => array(
                    array('firstCharacter' => 0, 'advance' => 0.5, 'fontSize' => 10, 'x' => 2, 'y' => 3, 'commandsBlob' => 0),
                    array('firstCharacter' => 1, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 1),
                    array('firstCharacter' => 2, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 0),
                ),
            ),
        ),
    ),
);
$whitespaceGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($whitespaceGlyphScenegraph);
$whitespaceGlyphDiagnostics = array_values(array_filter(
    $whitespaceGlyphResult['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'unsupported_text_glyph_command_blob' === ($diagnostic['code'] ?? null)
));
$assert(0 === count($whitespaceGlyphDiagnostics), 'whitespace-glyph-empty-path-no-warning');
$assert(str_contains($fileContent($whitespaceGlyphResult, 'index.html'), 'A B'), 'whitespace-glyph-preserves-text');
$whitespaceGlyphVisualNodes = $whitespaceGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
$whitespaceGlyphVisualNode = null;
foreach ( is_array($whitespaceGlyphVisualNodes) ? $whitespaceGlyphVisualNodes : array() as $visualNode ) {
    if ( is_array($visualNode) && 'text:whitespace-glyph' === ($visualNode['id'] ?? null) ) {
        $whitespaceGlyphVisualNode = $visualNode;
        break;
    }
}
$whitespaceGlyphPaths = $whitespaceGlyphVisualNode['text']['derived_layout']['glyph_paths'] ?? array();
$whitespaceGlyphPathData = array_values(array_filter(array_map(
    static fn ($glyphPath): ?string => is_array($glyphPath) && isset($glyphPath['data']) ? (string) $glyphPath['data'] : null,
    is_array($whitespaceGlyphPaths) ? $whitespaceGlyphPaths : array()
)));
$assert(array('M 0 0 Q 4 8 8 0 Z', 'M 0 0 Q 4 8 8 0 Z') === $whitespaceGlyphPathData, 'whitespace-glyph-keeps-drawable-paths-only');

$derivedTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($derivedTextLayoutScenegraph, array('render_text_glyph_paths' => true));
$derivedTextGlyphHtml = $fileContent($derivedTextGlyphResult, 'index.html');
$derivedTextGlyphCss = $fileContent($derivedTextGlyphResult, 'style.css');
$derivedTextGlyphVisualNodes = $derivedTextGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
$derivedTextGlyphVisualNode = null;
foreach ( is_array($derivedTextGlyphVisualNodes) ? $derivedTextGlyphVisualNodes : array() as $visualNode ) {
    if ( is_array($visualNode) && 'text:derived-layout' === ($visualNode['id'] ?? null) ) {
        $derivedTextGlyphVisualNode = $visualNode;
        break;
    }
}
$assert(str_contains($derivedTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'derived-text-glyph-svg-emitted');
$assert(str_contains($derivedTextGlyphHtml, 'aria-label="A B"'), 'derived-text-glyph-svg-label');
$assert(str_contains($derivedTextGlyphHtml, 'd="M 0 0 Q 4 8 8 0 Z"'), 'derived-text-glyph-svg-path');
$assert(str_contains($derivedTextGlyphHtml, 'transform="translate(2 3) scale(10 -10)"'), 'derived-text-glyph-svg-position');
$assert(str_contains($derivedTextGlyphHtml, 'transform="translate(10 20) scale(10 -10)"'), 'derived-text-glyph-svg-advance-through-space');
$assert(! str_contains($derivedTextGlyphHtml, 'transform="translate(5 20) scale(10 -10)"'), 'derived-text-glyph-svg-skips-space-path');
$assert(str_contains($derivedTextGlyphCss, '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}'), 'derived-text-glyph-svg-css');
$assert('svg_paths' === ($derivedTextGlyphVisualNode['text']['glyph_rendering'] ?? null), 'visual-node-derived-text-glyph-rendering-mode');

$symbolicTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Symbolic Text Glyph Fallback Fixture',
    'blobs' => array(array('bytes' => $quadraticCommandBlob)),
    'nodes' => array(
        array(
            'id'              => 'text:symbolic-glyph',
            'type'            => 'TEXT',
            'name'            => 'Checklist Text',
            'characters'      => "✔ Included\n✖ Excluded",
            'width'           => 120,
            'height'          => 48,
            'fontSize'        => 16,
            'derivedTextData' => array(
                'layoutSize' => array('x' => 120, 'y' => 48),
                'glyphs'     => array(array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 16, 'commandsBlob' => 0)),
            ),
        ),
    ),
), array('render_text_glyph_paths' => true));
$symbolicTextGlyphHtml = $fileContent($symbolicTextGlyphResult, 'index.html');
$symbolicTextGlyphVisualNode = null;
foreach ( $symbolicTextGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array() as $visualNode ) {
    if ( is_array($visualNode) && 'text:symbolic-glyph' === ($visualNode['id'] ?? null) ) {
        $symbolicTextGlyphVisualNode = $visualNode;
        break;
    }
}
$assert(str_contains($symbolicTextGlyphHtml, "✔ Included\n✖ Excluded"), 'symbolic-text-glyph-fallback-renders-dom-text');
$assert(! str_contains($symbolicTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'symbolic-text-glyph-fallback-avoids-svg-paths');
$assert('dom_text' === ($symbolicTextGlyphVisualNode['text']['glyph_rendering'] ?? null), 'symbolic-text-glyph-fallback-visual-metadata-dom');

$paragraphTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Paragraph Text Glyph Fallback Fixture',
    'blobs' => array(array('bytes' => $quadraticCommandBlob)),
    'nodes' => array(
        array(
            'id'              => 'text:paragraph-glyph',
            'type'            => 'TEXT',
            'name'            => 'Paragraph copy',
            'characters'      => 'This longer paragraph copy should remain real DOM text instead of SVG glyph paths because it needs browser text flow and selection.',
            'width'           => 240,
            'height'          => 80,
            'fontSize'        => 16,
            'derivedTextData' => array(
                'layoutSize' => array('x' => 240, 'y' => 80),
                'glyphs'     => array(array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 16, 'commandsBlob' => 0)),
            ),
        ),
    ),
), array('render_text_glyph_paths' => true));
$paragraphTextGlyphHtml = $fileContent($paragraphTextGlyphResult, 'index.html');
$assert(str_contains($paragraphTextGlyphHtml, 'This longer paragraph copy should remain real DOM text'), 'paragraph-text-glyph-fallback-renders-dom-text');
$assert(! str_contains($paragraphTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'paragraph-text-glyph-fallback-avoids-svg-paths');

$sentenceTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Sentence Text Glyph Fallback Fixture',
    'blobs' => array(array('bytes' => $quadraticCommandBlob)),
    'nodes' => array(
        array(
            'id'              => 'text:sentence-glyph',
            'type'            => 'TEXT',
            'name'            => 'Sentence copy',
            'characters'      => 'Sentence-style body copy should remain DOM text.',
            'width'           => 240,
            'height'          => 32,
            'fontSize'        => 16,
            'derivedTextData' => array(
                'layoutSize' => array('x' => 240, 'y' => 32),
                'glyphs'     => array(array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 16, 'commandsBlob' => 0)),
            ),
        ),
    ),
), array('render_text_glyph_paths' => true));
$sentenceTextGlyphHtml = $fileContent($sentenceTextGlyphResult, 'index.html');
$assert(str_contains($sentenceTextGlyphHtml, 'Sentence-style body copy should remain DOM text.'), 'sentence-text-glyph-fallback-renders-dom-text');
$assert(! str_contains($sentenceTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'sentence-text-glyph-fallback-avoids-svg-paths');

$multilineHeadingGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multiline Heading Glyph Fixture',
    'blobs' => array(array('bytes' => $quadraticCommandBlob)),
    'nodes' => array(
        array(
            'id'              => 'text:multiline-heading-glyph',
            'type'            => 'TEXT',
            'name'            => 'Short Wrapped Heading',
            'characters'      => "Short\nwrapped\nheading text",
            'width'           => 120,
            'height'          => 90,
            'style'           => array('fontWeight' => 700),
            'derivedTextData' => array(
                'baselines' => array(
                    array('firstCharacter' => 0, 'endCharacter' => 5, 'position' => array('x' => 0, 'y' => 20)),
                    array('firstCharacter' => 6, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 45)),
                    array('firstCharacter' => 14, 'endCharacter' => 26, 'position' => array('x' => 0, 'y' => 70)),
                ),
                'glyphs' => array(
                    array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                    array('firstCharacter' => 6, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                    array('firstCharacter' => 14, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                ),
            ),
        ),
    ),
), array('render_text_glyph_paths' => true));
$multilineHeadingGlyphHtml = $fileContent($multilineHeadingGlyphResult, 'index.html');
$assert(str_contains($multilineHeadingGlyphHtml, "aria-label=\"Short\nwrapped\nheading text\""), 'multiline-heading-glyph-renders-svg-label');
$assert(str_contains($multilineHeadingGlyphHtml, 'data-figma-text-glyphs="true"'), 'multiline-heading-glyph-renders-svg');

$multilineLargeDisplayGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multiline Large Display Glyph Fixture',
    'blobs' => array(array('bytes' => $quadraticCommandBlob)),
    'nodes' => array(
        array(
            'id'              => 'text:multiline-large-display-glyph',
            'type'            => 'TEXT',
            'name'            => 'Large Wrapped Display',
            'characters'      => "Large\nwrapped",
            'width'           => 160,
            'height'          => 80,
            'fontSize'        => 34,
            'style'           => array('fontWeight' => 400),
            'derivedTextData' => array(
                'baselines' => array(
                    array('firstCharacter' => 0, 'endCharacter' => 5, 'position' => array('x' => 0, 'y' => 34)),
                    array('firstCharacter' => 6, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 72)),
                ),
                'glyphs' => array(
                    array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 34, 'commandsBlob' => 0),
                    array('firstCharacter' => 6, 'advance' => 1, 'fontSize' => 34, 'commandsBlob' => 0),
                ),
            ),
        ),
    ),
), array('render_text_glyph_paths' => true));
$multilineLargeDisplayGlyphHtml = $fileContent($multilineLargeDisplayGlyphResult, 'index.html');
$assert(str_contains($multilineLargeDisplayGlyphHtml, "aria-label=\"Large\nwrapped\""), 'multiline-large-display-glyph-renders-svg-label');
$assert(str_contains($multilineLargeDisplayGlyphHtml, 'data-figma-text-glyphs="true"'), 'multiline-large-display-glyph-renders-svg');

$multilineCopyGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multiline Copy Glyph Fixture',
    'blobs' => array(array('bytes' => $quadraticCommandBlob)),
    'nodes' => array(
        array(
            'id'              => 'text:multiline-copy-glyph',
            'type'            => 'TEXT',
            'name'            => 'Wrapped Copy',
            'characters'      => "Short\nwrapped\ncopy",
            'width'           => 120,
            'height'          => 60,
            'style'           => array('fontWeight' => 400),
            'derivedTextData' => array(
                'baselines' => array(
                    array('firstCharacter' => 0, 'endCharacter' => 5, 'position' => array('x' => 0, 'y' => 20)),
                    array('firstCharacter' => 6, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 45)),
                ),
                'glyphs' => array(
                    array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                    array('firstCharacter' => 6, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                ),
            ),
        ),
    ),
), array('render_text_glyph_paths' => true));
$multilineCopyGlyphHtml = $fileContent($multilineCopyGlyphResult, 'index.html');
$assert(str_contains($multilineCopyGlyphHtml, "Short\nwrapped\ncopy"), 'multiline-copy-glyph-fallback-renders-dom-text');
$assert(! str_contains($multilineCopyGlyphHtml, 'data-figma-text-glyphs="true"'), 'multiline-copy-glyph-fallback-avoids-svg');

$derivedLineBreakResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Derived Line Break Fixture',
    'nodes' => array(
        array(
            'id'              => 'text:derived-lines',
            'type'            => 'TEXT',
            'name'            => 'Measured Lines',
            'characters'      => 'First line Second line',
            'width'           => 120,
            'height'          => 44,
            'lineHeightPx'    => 40,
            'derivedTextData' => array(
                'layoutSize' => array('x' => 120, 'y' => 44),
                'baselines'  => array(
                    array('firstCharacter' => 0, 'endCharacter' => 10, 'position' => array('x' => 0, 'y' => 16)),
                    array('firstCharacter' => 11, 'endCharacter' => 22, 'position' => array('x' => 0, 'y' => 38)),
                ),
            ),
        ),
    ),
));
$derivedLineBreakHtml = $fileContent($derivedLineBreakResult, 'index.html');
$derivedLineBreakCss = $fileContent($derivedLineBreakResult, 'style.css');
$assert(str_contains($derivedLineBreakHtml, "First line\nSecond line"), 'derived-baselines-insert-line-breaks');
$assert(str_contains($derivedLineBreakCss, '.figma-node-text-derived-lines-measured-lines{width:120px;height:44px;line-height:22px;white-space:pre-line}'), 'derived-baselines-enable-pre-line');
$assert(! str_contains($derivedLineBreakCss, 'line-height:40px;line-height:22px'), 'derived-baselines-replace-source-line-height');

$derivedMeasuredLineHeightResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Derived Measured Line Height Fixture',
    'nodes' => array(
        array(
            'id'              => 'text:derived-measured-line-height',
            'type'            => 'TEXT',
            'name'            => 'Measured Line Height',
            'characters'      => 'First line Second line',
            'width'           => 120,
            'height'          => 40,
            'lineHeightPx'    => 28,
            'derivedTextData' => array(
                'layoutSize' => array('x' => 120, 'y' => 40),
                'baselines'  => array(
                    array('firstCharacter' => 0, 'endCharacter' => 10, 'lineHeight' => 20, 'position' => array('x' => 0, 'y' => 15)),
                    array('firstCharacter' => 11, 'endCharacter' => 22, 'lineHeight' => 20, 'position' => array('x' => 0, 'y' => 38)),
                ),
            ),
        ),
    ),
));
$derivedMeasuredLineHeightCss = $fileContent($derivedMeasuredLineHeightResult, 'style.css');
$derivedMeasuredLineHeightDiagnostics = $derivedMeasuredLineHeightResult['source_reports']['figma']['html']['node_style_diagnostics'] ?? array();
$derivedMeasuredLineHeightDiagnostic = null;
foreach ( is_array($derivedMeasuredLineHeightDiagnostics) ? $derivedMeasuredLineHeightDiagnostics : array() as $styleDiagnostic ) {
    if ( 'text:derived-measured-line-height' === ($styleDiagnostic['node']['id'] ?? null) ) {
        $derivedMeasuredLineHeightDiagnostic = $styleDiagnostic;
    }
}
$assert(str_contains($derivedMeasuredLineHeightCss, '.figma-node-text-derived-measured-line-height-measured-line-height{width:120px;height:40px;line-height:20px;white-space:pre-line}'), 'derived-baselines-prefer-measured-line-height');
$assert('20px' === ($derivedMeasuredLineHeightDiagnostic['expected']['line_height'] ?? null), 'derived-baselines-measured-line-height-expected-diagnostic');
$assert('20px' === ($derivedMeasuredLineHeightDiagnostic['emitted']['line_height'] ?? null), 'derived-baselines-measured-line-height-emitted-diagnostic');
$assert(array() === ($derivedMeasuredLineHeightDiagnostic['mismatches'] ?? null), 'derived-baselines-measured-line-height-no-diagnostic-mismatch');

$parityBuilder = new ParityReportBuilder();
$pendingParity = $parityBuilder->build(array(
    'status'    => 'pending',
    'reason'    => 'queued_for_browser_runner',
    'artifacts' => array(
        'report_path' => 'artifacts/parity-report.json',
    ),
    'dom_boxes_path' => 'artifacts/dom-boxes.json',
    'layout_report_path' => 'artifacts/layout-report.json',
    'render_evidence_path' => 'artifacts/render-evidence.json',
    'layout_mismatch_count' => 3,
    'layout_top_nodes' => array(
        array('id' => '1:2', 'name' => 'Hero title'),
    ),
));
$layoutOnlyParity = $parityBuilder->build(array(
    'status' => 'pass',
    'dom_boxes_path' => 'artifacts/dom-boxes.json',
    'layout_mismatch_count' => 0,
));
$screenshotCandidateParity = $parityBuilder->build(array(
    'source_screenshot_path' => 'artifacts/source-candidate.png',
    'source_screenshot_exists' => false,
    'source_screenshot_readable' => false,
    'generated_screenshot_path' => 'artifacts/generated-candidate.png',
    'generated_screenshot_exists' => false,
    'generated_screenshot_readable' => false,
));
$comparedParity = $parityBuilder->build(array(
    'status'    => 'compared',
    'artifacts' => array(
        'source_screenshot_path'    => 'artifacts/source.png',
        'generated_screenshot_path' => 'artifacts/generated.png',
        'diff_image_path'           => 'artifacts/diff.png',
    ),
    'source_screenshot_path' => 'artifacts/source.png',
    'generated_screenshot_path' => 'artifacts/generated.png',
    'diff_image_path' => 'artifacts/diff.png',
    'frame_id' => '1:1',
    'viewport' => array(
        'width' => 1200,
        'height' => 800,
    ),
    'diff_summary' => array(
        'changed_pixels' => 42,
    ),
    'pixel_mismatch_count' => 42,
    'pixel_mismatch_ratio' => 0.01,
    'threshold' => 0.02,
));
$passingParity = $parityBuilder->build(array(
    'status' => 'pass',
    'source_screenshot_url' => 'https://example.com/artifacts/source.png',
    'generated_screenshot_artifact' => 'homeboy://runs/123/generated.png',
    'diff_image_artifact' => 'homeboy://runs/123/diff.png',
    'pixel_mismatch_count' => 10,
    'pixel_mismatch_ratio' => 0.005,
    'threshold' => 0.01,
));
$failingParity = $parityBuilder->build(array(
    'status' => 'fail',
    'pixel_mismatch_count' => 500,
    'pixel_mismatch_ratio' => 0.05,
    'threshold' => 0.01,
));
$notRunParity = $parityBuilder->build();
$unknownParity = $parityBuilder->build(array(
    'status' => 'browser_timeout',
));
$assert('pending' === ($pendingParity['status'] ?? null), 'parity-pending-status');
$assert('artifacts/parity-report.json' === ($pendingParity['artifacts']['report_path'] ?? null), 'parity-pending-artifact-path');
$assert('artifacts/dom-boxes.json' === ($pendingParity['artifacts']['dom_boxes_path'] ?? null), 'parity-dom-boxes-artifact-path');
$assert('artifacts/layout-report.json' === ($pendingParity['artifacts']['layout_report_path'] ?? null), 'parity-layout-report-artifact-path');
$assert('artifacts/render-evidence.json' === ($pendingParity['artifacts']['render_evidence_path'] ?? null), 'parity-render-evidence-artifact-path');
$assert(3 === ($pendingParity['layout_diagnostics']['mismatch_count'] ?? null), 'parity-layout-mismatch-count');
$assert('1:2' === ($pendingParity['layout_diagnostics']['top_nodes'][0]['id'] ?? null), 'parity-layout-top-node');
$assert('fail' === ($pendingParity['layout_evidence']['status'] ?? null), 'parity-layout-evidence-status-fail');
$assert('pending' === ($pendingParity['render_style_evidence']['status'] ?? null), 'parity-render-style-evidence-pending');
$assert('not_run' === ($pendingParity['visual_pixel_status'] ?? null), 'parity-pending-visual-pixel-not-run');
$assert('pass' === ($layoutOnlyParity['layout_evidence']['status'] ?? null), 'parity-layout-only-layout-evidence-pass');
$assert(0 === ($layoutOnlyParity['layout_evidence']['mismatch_count'] ?? null), 'parity-layout-only-layout-count-zero');
$assert(! array_key_exists('pixel_mismatch_count', $layoutOnlyParity['metrics'] ?? array()), 'parity-layout-only-no-pixel-count');
$assert('not_run' === ($layoutOnlyParity['visual_pixel_status'] ?? null), 'parity-layout-only-visual-pixel-not-run');
$assert('not_run' === ($layoutOnlyParity['render_style_evidence']['status'] ?? null), 'parity-layout-only-render-style-not-run');
$assert('pending' === ($screenshotCandidateParity['status'] ?? null), 'parity-screenshot-candidate-pending');
$assert('screenshot_evidence_configured' === ($screenshotCandidateParity['reason'] ?? null), 'parity-screenshot-candidate-reason');
$assert('not_run' === ($screenshotCandidateParity['visual_pixel_status'] ?? null), 'parity-screenshot-candidate-visual-not-run');
$assert('artifacts/source-candidate.png' === ($screenshotCandidateParity['source']['screenshot_path'] ?? null), 'parity-screenshot-candidate-source-path');
$assert(false === ($screenshotCandidateParity['source']['screenshot_exists'] ?? null), 'parity-screenshot-candidate-source-exists-false');
$assert(false === ($screenshotCandidateParity['generated']['screenshot_readable'] ?? null), 'parity-screenshot-candidate-generated-readable-false');
$assert('compared' === ($comparedParity['status'] ?? null), 'parity-compared-status');
$assert('artifacts/source.png' === ($comparedParity['source']['screenshot_path'] ?? null), 'parity-source-screenshot-path');
$assert('artifacts/generated.png' === ($comparedParity['generated']['screenshot_path'] ?? null), 'parity-generated-screenshot-path');
$assert('artifacts/diff.png' === ($comparedParity['diff']['image_path'] ?? null), 'parity-diff-image-path');
$assert('1:1' === ($comparedParity['source']['frame_id'] ?? null), 'parity-source-frame-id');
$assert(1200 === ($comparedParity['viewport']['width'] ?? null), 'parity-viewport-width');
$assert(42 === ($comparedParity['diff_summary']['changed_pixels'] ?? null), 'parity-diff-summary');
$assert(42 === ($comparedParity['metrics']['pixel_mismatch_count'] ?? null), 'parity-pixel-mismatch-count');
$assert(0.01 === ($comparedParity['metrics']['pixel_mismatch_ratio'] ?? null), 'parity-pixel-mismatch-ratio');
$assert(true === ($comparedParity['diff_summary']['passed'] ?? null), 'parity-compared-passed-threshold');
$assert('pass' === ($passingParity['status'] ?? null), 'parity-pass-status');
$assert('https://example.com/artifacts/source.png' === ($passingParity['source']['screenshot_url'] ?? null), 'parity-pass-source-url');
$assert('homeboy://runs/123/generated.png' === ($passingParity['generated']['screenshot_artifact'] ?? null), 'parity-pass-generated-artifact');
$assert('homeboy://runs/123/diff.png' === ($passingParity['diff']['image_artifact'] ?? null), 'parity-pass-diff-artifact');
$assert(true === ($passingParity['diff_summary']['passed'] ?? null), 'parity-pass-threshold');
$assert('fail' === ($failingParity['status'] ?? null), 'parity-fail-status');
$assert(false === ($failingParity['diff_summary']['passed'] ?? null), 'parity-fail-threshold');
$assert('not_run' === ($notRunParity['status'] ?? null), 'parity-not-run-status');
$assert('pending' === ($unknownParity['status'] ?? null), 'parity-unknown-status-falls-back-to-pending');

// Single-viewport input remains backward compatible: no breakpoints list, aggregate mirrors status.
$assert(array() === ($comparedParity['breakpoints'] ?? null), 'parity-single-viewport-no-breakpoints');
$assert('compared' === ($comparedParity['aggregate_status'] ?? null), 'parity-single-viewport-aggregate-mirrors-status');
$assert('not_run' === ($notRunParity['aggregate_status'] ?? null), 'parity-not-run-aggregate-mirrors-status');

// Multi-breakpoint envelope: each entry is normalized and rolled up into aggregate_status.
$multiBreakpointPass = $parityBuilder->build(array(
    'status'   => 'pass',
    'frame_id' => '10:0',
    'breakpoints' => array(
        array(
            'status'   => 'pass',
            'frame_id' => '10:1',
            'source_screenshot_url'    => 'https://artifacts.example.test/mobile-source.png',
            'generated_screenshot_url' => 'https://artifacts.example.test/mobile-generated.png',
            'diff_image_url'           => 'https://artifacts.example.test/mobile-diff.png',
            'pixel_mismatch_count'     => 8,
            'pixel_mismatch_ratio'     => 0.004,
            'threshold'                => 0.01,
            'viewport'                 => array('width' => 375, 'height' => 812),
        ),
        array(
            'status'   => 'pass',
            'frame_id' => '10:2',
            'pixel_mismatch_count' => 12,
            'pixel_mismatch_ratio' => 0.006,
            'threshold'            => 0.01,
            'viewport'             => array('width' => 1200, 'height' => 800),
        ),
    ),
));
$multiBreakpointFail = $parityBuilder->build(array(
    'status' => 'compared',
    'breakpoints' => array(
        array(
            'status'               => 'pass',
            'pixel_mismatch_ratio' => 0.004,
            'threshold'            => 0.01,
            'viewport'             => array('width' => 375, 'height' => 812),
        ),
        array(
            'status'               => 'fail',
            'pixel_mismatch_ratio' => 0.08,
            'threshold'            => 0.01,
            'viewport'             => array('width' => 1200, 'height' => 800),
        ),
    ),
));
$multiBreakpointPending = $parityBuilder->build(array(
    'status' => 'pending',
    'breakpoints' => array(
        array(
            'status'               => 'pass',
            'pixel_mismatch_ratio' => 0.004,
            'threshold'            => 0.01,
            'viewport'             => array('width' => 375, 'height' => 812),
        ),
        array(
            'status'   => 'not_run',
            'viewport' => array('width' => 1200, 'height' => 800),
        ),
    ),
));

$assert(2 === count($multiBreakpointPass['breakpoints'] ?? array()), 'parity-breakpoints-count');
$assert(375 === ($multiBreakpointPass['breakpoints'][0]['viewport']['width'] ?? null), 'parity-breakpoint-viewport-width');
$assert('10:1' === ($multiBreakpointPass['breakpoints'][0]['frame_id'] ?? null), 'parity-breakpoint-frame-id');
$assert('https://artifacts.example.test/mobile-source.png' === ($multiBreakpointPass['breakpoints'][0]['source']['screenshot_url'] ?? null), 'parity-breakpoint-source-url');
$assert('https://artifacts.example.test/mobile-diff.png' === ($multiBreakpointPass['breakpoints'][0]['diff']['image_url'] ?? null), 'parity-breakpoint-diff-url');
$assert(8 === ($multiBreakpointPass['breakpoints'][0]['metrics']['pixel_mismatch_count'] ?? null), 'parity-breakpoint-pixel-count');
$assert(true === ($multiBreakpointPass['breakpoints'][0]['diff_summary']['passed'] ?? null), 'parity-breakpoint-passed-threshold');
$assert('pass' === ($multiBreakpointPass['breakpoints'][1]['status'] ?? null), 'parity-breakpoint-second-pass');
$assert('pass' === ($multiBreakpointPass['aggregate_status'] ?? null), 'parity-aggregate-all-pass');
$assert('fail' === ($multiBreakpointFail['aggregate_status'] ?? null), 'parity-aggregate-any-fail');
$assert('pending' === ($multiBreakpointPending['aggregate_status'] ?? null), 'parity-aggregate-partial-not-run-pending');

if ( function_exists('imagecreatetruecolor') && function_exists('imagepng') ) {
    $sourceImagePath = tempnam(sys_get_temp_dir(), 'figma-source-') . '.png';
    $generatedImagePath = tempnam(sys_get_temp_dir(), 'figma-generated-') . '.png';
    $sourceImage = imagecreatetruecolor(4, 4);
    $generatedImage = imagecreatetruecolor(4, 4);
    $whiteSource = imagecolorallocate($sourceImage, 255, 255, 255);
    $whiteGenerated = imagecolorallocate($generatedImage, 255, 255, 255);
    imagefilledrectangle($sourceImage, 0, 0, 3, 3, $whiteSource);
    imagefilledrectangle($generatedImage, 0, 0, 3, 3, $whiteGenerated);
    imagesetpixel($generatedImage, 1, 1, imagecolorallocate($generatedImage, 0, 0, 0));
    imagepng($sourceImage, $sourceImagePath);
    imagepng($generatedImage, $generatedImagePath);

    $visualAttribution = ( new VisualAttributionReportBuilder() )->build(
        array(
            'source_reports' => array(
                'figma' => array(
                    'html' => array(
                        'node_style_diagnostics' => array(
                            array(
                                'node' => array('id' => 'node:1', 'name' => 'Node 1', 'type' => 'RECTANGLE', 'class' => 'figma-node-node-1'),
                                'expected' => array('width' => '2px', 'height' => '2px', 'x' => '1px', 'y' => '1px', 'background' => '#ffffff'),
                                'emitted' => array('width' => '2px', 'height' => '2px', 'x' => '1px', 'y' => '1px', 'background' => '#ffffff'),
                                'mismatches' => array(),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        $sourceImagePath,
        $generatedImagePath,
        array('threshold' => 24, 'material_threshold' => 96, 'severe_threshold' => 192, 'limit' => 5)
    );
    @unlink($sourceImagePath);
    @unlink($generatedImagePath);

    $assert('blocks-engine/figma-transformer/visual-attribution/v1' === ($visualAttribution['schema'] ?? null), 'visual-attribution-schema');
    $assert('success' === ($visualAttribution['status'] ?? null), 'visual-attribution-success');
    $assert(1 === ($visualAttribution['top_nodes'][0]['diff']['mismatch_pixels'] ?? null), 'visual-attribution-node-mismatch-count');
    $assert(1 === ($visualAttribution['top_nodes'][0]['diff']['material_mismatch_pixels'] ?? null), 'visual-attribution-node-material-mismatch-count');
    $assert(1 === ($visualAttribution['top_nodes'][0]['diff']['severe_mismatch_pixels'] ?? null), 'visual-attribution-node-severe-mismatch-count');
    $assert(741 === ($visualAttribution['top_nodes'][0]['diff']['material_delta_score'] ?? null), 'visual-attribution-node-material-delta-score');
    $assert(array('gt24' => 1, 'gt48' => 1, 'gt96' => 1, 'gt192' => 1) === ($visualAttribution['top_nodes'][0]['diff']['severity_buckets'] ?? null), 'visual-attribution-node-severity-buckets');
    $assert(1 === ($visualAttribution['totals']['material_mismatch_pixels'] ?? null), 'visual-attribution-totals-material-mismatch-count');
    $assert(array('background', 'positioned') === ($visualAttribution['top_nodes'][0]['features'] ?? null), 'visual-attribution-node-features');
}

$fixture = blocks_engine_figma_transformer_create_fig_wrapper_fixture();
$fileResult = blocks_engine_figma_transformer_transform_file($fixture);
@unlink($fixture);

$canvas = $fileResult['source_reports']['figma']['archive']['canvas'] ?? array();
$chunks = $canvas['chunks'] ?? array();
$diagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $fileResult['diagnostics'] ?? array()
);
$zstdCapability = new ZstdCapability();
$zstdStatus = $zstdCapability->status();
$zstdCapabilityDiagnostic = $zstdCapability->diagnostic('ContractTest', 0);
$zstdCapabilityCode = (string) ($zstdCapabilityDiagnostic['code'] ?? '');
$zstdDiagnostic = null;
foreach ( $fileResult['diagnostics'] ?? array() as $diagnostic ) {
    if ( $zstdCapabilityCode === ($diagnostic['code'] ?? null) ) {
        $zstdDiagnostic = $diagnostic;
        break;
    }
}

$assert('success_with_warnings' === ($fileResult['status'] ?? null), 'file-transform-status');
$assert('fig-kiwi' === ($canvas['prelude'] ?? null), 'fig-kiwi-prelude');
$assert(106 === ($canvas['version'] ?? null), 'fig-kiwi-version');
$assert('inner.fig' === ($fileResult['source_reports']['figma']['input']['nested_fig'] ?? null), 'wrapper-nested-fig');
$assert(5 === count($chunks), 'fig-kiwi-chunk-count');
$assert('zlib' === ($chunks[0]['compression'] ?? null), 'fig-kiwi-first-chunk-zlib');
$assert('json' === ($chunks[0]['payload']['classification'] ?? null), 'fig-kiwi-first-chunk-json');
$assert(isset($chunks[0]['payload']['json']['nodes']), 'fig-kiwi-first-chunk-nodes-candidate');
$assert('json_invalid' === ($chunks[1]['payload']['classification'] ?? null), 'fig-kiwi-second-chunk-json-invalid');
$assert(isset($chunks[2]['payload']['json']['NODE_CHANGES']), 'fig-kiwi-third-chunk-node-changes');
$assert('binary' === ($chunks[3]['payload']['classification'] ?? null), 'fig-kiwi-fourth-chunk-binary');
$assert('zstd' === ($chunks[4]['compression'] ?? null), 'fig-kiwi-fifth-chunk-zstd');
$assert(in_array($zstdCapabilityCode, $diagnosticCodes, true), 'fig-kiwi-zstd-capability-diagnostic');
$assert(is_bool($zstdStatus['available'] ?? null), 'zstd-status-available-bool');
$assert(is_bool($zstdStatus['extension_loaded'] ?? null), 'zstd-status-extension-loaded-bool');
$assert(is_array($zstdStatus['functions'] ?? null), 'zstd-status-functions-array');
$assert(array_key_exists('zstd_uncompress', $zstdStatus['functions'] ?? array()), 'zstd-status-uncompress-function');
$assert(array_key_exists('adapter_registered', $zstdStatus), 'zstd-status-adapter-registered-key');
$assert(array_key_exists('wordpress_filter_registered', $zstdStatus), 'zstd-status-wordpress-filter-registered-key');
$assert(($zstdStatus['available'] ?? null) === (null !== ($zstdStatus['provider'] ?? null)), 'zstd-status-available-matches-provider');
$assert(($zstdStatus['available'] ?? null) === ($zstdDiagnostic['context']['available'] ?? null), 'fig-kiwi-zstd-diagnostic-availability-context');
if ( true === ($zstdStatus['available'] ?? false) && function_exists('zstd_compress') ) {
    $zstdCompressed = zstd_compress('contract zstd round trip');
    $zstdRoundTrip = false !== $zstdCompressed ? $zstdCapability->uncompress($zstdCompressed, 'ContractTest', 1) : array('data' => null, 'diagnostics' => array());
    $assert('contract zstd round trip' === ($zstdRoundTrip['data'] ?? null), 'zstd-real-round-trip');
    $assert(isset($chunks[4]['inflated_bytes']), 'fig-kiwi-zstd-real-fixture-inflated');
} else {
    $zstdUnavailable = $zstdCapability->uncompress("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame', 'ContractTest', 1);
    $assert(null === ($zstdUnavailable['data'] ?? null), 'zstd-unavailable-returns-null');
    $assert(in_array((string) ($zstdUnavailable['diagnostics'][0]['code'] ?? ''), array('figma_transformer_zstd_extension_missing', 'figma_transformer_zstd_function_missing'), true), 'zstd-unavailable-diagnostic-code');
}

$adapterCapability = new ZstdCapability(static function (string $payload): string|false {
    if ( "\x28\xb5\x2f\xfd" !== substr($payload, 0, 4) ) {
        return false;
    }

    return json_encode(array('NODE_CHANGES' => array()), JSON_THROW_ON_ERROR);
});
$adapterStatus = $adapterCapability->status();
$adapterResult = $adapterCapability->uncompress("\x28\xb5\x2f\xfd" . 'adapter-frame', 'ContractTest', 2);
$adapterCanvasResult = ( new FigKiwiParser($adapterCapability) )->parse(
    'fig-kiwi'
    . pack('V', 106)
    . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'adapter-frame')
);
$failingAdapterResult = ( new ZstdCapability(static fn (): false => false) )->uncompress("\x28\xb5\x2f\xfd" . 'adapter-frame', 'ContractTest', 3);
$commandAdapterResult = ( new ZstdCapability(new ZstdCommandDecoder(array(PHP_BINARY, '-r', '$payload = stream_get_contents(STDIN); fwrite(STDOUT, $payload);'))) )->uncompress('command adapter bytes', 'ContractTest', 4);

$assert(true === ($adapterStatus['available'] ?? null), 'zstd-adapter-status-available');
$assert('adapter' === ($adapterStatus['provider'] ?? null) || 'ext-zstd' === ($adapterStatus['provider'] ?? null), 'zstd-adapter-status-provider');
$assert('{"NODE_CHANGES":[]}' === ($adapterResult['data'] ?? null), 'zstd-adapter-decodes-payload');
$assert('json' === ($adapterCanvasResult['canvas']['chunks'][0]['payload']['classification'] ?? null), 'fig-kiwi-zstd-adapter-classifies-json');
$assert('figma_transformer_zstd_adapter_failed' === ($failingAdapterResult['diagnostics'][0]['code'] ?? null), 'zstd-adapter-failure-diagnostic');
$assert('command adapter bytes' === ($commandAdapterResult['data'] ?? null), 'zstd-command-adapter-decodes-payload');
$assert('figma_transformer_zstd_command_used' === ($commandAdapterResult['diagnostics'][1]['code'] ?? null), 'zstd-command-adapter-diagnostic');
$assert(! empty($fileResult['files']), 'file-transform-renders-decoded-scenegraph');
$assert(4 === ($fileResult['metrics']['node_count'] ?? null), 'file-transform-node-count');
$assert(2 === ($fileResult['metrics']['decoded_payload_candidate_count'] ?? null), 'file-transform-decoded-candidate-count');
$assert(2 === ($fileResult['metrics']['selected_decoded_payload_index'] ?? null), 'file-transform-selected-node-changes-index');
$assert('NODE_CHANGES' === ($fileResult['source_reports']['figma']['decoded_scenegraph']['shape'] ?? null), 'file-transform-selected-node-changes-shape');
$assert(isset($fileResult['source_reports']['figma']['html']), 'file-transform-html-source-report');
$assert('blocks-engine/figma-transformer/compiled-site/v1' === ($fileResult['source_reports']['compiled_site']['schema'] ?? null), 'file-transform-compiled-site-source-report');
$assert('synthetic' === ($fileResult['source_reports']['figma']['assets'][0]['id'] ?? null), 'archive-asset-id');
$assert('images/synthetic' === ($fileResult['source_reports']['figma']['assets'][0]['path'] ?? null), 'archive-asset-path');
$assert('asset' === ($fileResult['source_reports']['figma']['assets'][0]['content'] ?? null), 'archive-asset-content');
$assert('assets/synthetic.bin' === ($fileResult['assets'][0]['path'] ?? null), 'archive-asset-emitted-from-decoded-scenegraph');

$assetMetadataFixture = SyntheticFigKiwiFixtureBuilder::figArchive(
    SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(array('metadata' => array('ignored' => true))))),
    array('images/metadata-only' => "\x89PNG\r\n\x1a\n" . str_repeat('asset-bytes', 20))
);
$assetMetadataResult = ( new FigArchiveReader() )->read($assetMetadataFixture, array('include_asset_content' => false));
@unlink($assetMetadataFixture);
$assert('metadata-only' === ($assetMetadataResult['assets'][0]['id'] ?? null), 'archive-asset-metadata-id');
$assert('image/png' === ($assetMetadataResult['assets'][0]['mime_type'] ?? null), 'archive-asset-metadata-sniffs-mime');
$assert(! array_key_exists('content', $assetMetadataResult['assets'][0] ?? array()), 'archive-asset-metadata-omits-content');

$pendingFixture = blocks_engine_figma_transformer_create_pending_decoder_fig_wrapper_fixture();
$pendingResult = blocks_engine_figma_transformer_transform_file($pendingFixture);
@unlink($pendingFixture);
$pendingDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $pendingResult['diagnostics'] ?? array()
);
$assert('unsupported_decoder_pending' === ($pendingResult['status'] ?? null), 'pending-decoder-status');
$assert(in_array('figma_transformer_decoded_scenegraph_missing', $pendingDiagnosticCodes, true), 'pending-decoder-diagnostic');

$kiwiSchemaBytes = blocks_engine_figma_transformer_kiwi_schema_fixture();
$kiwiMessageBytes = blocks_engine_figma_transformer_kiwi_message_fixture();
$kiwiDecoder = new FigKiwiDecoder();
$kiwiSchemaResult = $kiwiDecoder->decodeSchema($kiwiSchemaBytes);
$kiwiMessageResult = $kiwiDecoder->decodeMessage($kiwiMessageBytes, $kiwiSchemaResult['schema'] ?? array());
$assert(null !== ($kiwiSchemaResult['schema'] ?? null), 'kiwi-schema-decodes');
$assert('NODE_CHANGES' === ($kiwiMessageResult['message']['type'] ?? null), 'kiwi-message-enum-decodes');
$assert(array('alpha', 'beta') === ($kiwiMessageResult['message']['nodeChanges'] ?? null), 'kiwi-message-array-decodes');

$injectedParser = new FigKiwiParser(new ZstdCapability(static fn (string $payload, array $context): string => $kiwiMessageBytes));
$injectedCanvas = $injectedParser->parse(
    'fig-kiwi'
    . pack('V', 106)
    . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate($kiwiSchemaBytes))
    . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame')
);
$injectedChunks = $injectedCanvas['canvas']['chunks'] ?? array();
$injectedDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $injectedCanvas['diagnostics'] ?? array()
);
$assert('kiwi_schema' === ($injectedChunks[0]['payload']['classification'] ?? null), 'kiwi-parser-classifies-schema');
$assert('kiwi_message' === ($injectedChunks[1]['payload']['classification'] ?? null), 'kiwi-parser-classifies-message');
$assert('NODE_CHANGES' === ($injectedChunks[1]['payload']['kiwi_message']['type'] ?? null), 'kiwi-parser-message-type');
$assert(in_array('figma_transformer_zstd_adapter_available', $injectedDiagnosticCodes, true), 'zstd-injected-decoder-diagnostic');

$guardedCanvas = ( new FigKiwiParser(new ZstdCapability(static fn (string $payload, array $context): string => $kiwiMessageBytes)) )->parse(
    'fig-kiwi'
    . pack('V', 106)
    . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate($kiwiSchemaBytes))
    . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame'),
    array('max_kiwi_message_decode_bytes' => 1)
);
$guardedChunks = $guardedCanvas['canvas']['chunks'] ?? array();
$guardedDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $guardedCanvas['diagnostics'] ?? array()
);
$assert('kiwi_message' === ($guardedChunks[1]['payload']['classification'] ?? null), 'kiwi-parser-selectively-decodes-oversized-message');
$assert('selective' === ($guardedChunks[1]['payload']['kiwi_message_decode'] ?? null), 'kiwi-parser-selective-message-mode');
$assert(in_array('figma_transformer_kiwi_message_selective_decode_used', $guardedDiagnosticCodes, true), 'kiwi-parser-selective-message-diagnostic');

$wirePayload = SyntheticFigKiwiFixtureBuilder::sampleWirePayload();
$wireCanvasResult = ( new FigKiwiParser() )->parse(
    SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk($wirePayload)))
);
$wire = $wireCanvasResult['canvas']['chunks'][0]['payload']['wire'] ?? array();
$wireRecords = $wire['records'] ?? array();

$assert('binary' === ($wireCanvasResult['canvas']['chunks'][0]['payload']['classification'] ?? null), 'fig-kiwi-wire-payload-remains-binary');
$assert('protobuf_wire' === ($wire['format'] ?? null), 'fig-kiwi-wire-format');
$assert(true === ($wire['complete'] ?? null), 'fig-kiwi-wire-complete');
$assert(3 === ($wire['record_count'] ?? null), 'fig-kiwi-wire-record-count');
$assert(1 === ($wireRecords[0]['field_number'] ?? null), 'fig-kiwi-wire-varint-field-number');
$assert(0 === ($wireRecords[0]['wire_type'] ?? null), 'fig-kiwi-wire-varint-type');
$assert(150 === ($wireRecords[0]['value'] ?? null), 'fig-kiwi-wire-varint-value');
$assert('hello' === ($wireRecords[1]['text_preview'] ?? null), 'fig-kiwi-wire-length-text-preview');
$assert('01020304' === ($wireRecords[2]['preview_hex'] ?? null), 'fig-kiwi-wire-fixed32-preview');

$unknownBinaryResult = ( new FigKiwiParser() )->parse(
    SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk("\x00\x01\x02unknown")))
);
$unknownBinaryPayload = $unknownBinaryResult['canvas']['chunks'][0]['payload'] ?? array();
$assert('binary' === ($unknownBinaryPayload['classification'] ?? null), 'fig-kiwi-unknown-binary-classification');
$assert(10 === ($unknownBinaryPayload['bytes'] ?? null), 'fig-kiwi-unknown-binary-byte-count');
$assert('000102756e6b6e6f776e' === ($unknownBinaryPayload['preview_hex'] ?? null), 'fig-kiwi-unknown-binary-preview');
$assert('zero_field_key' === ($unknownBinaryPayload['wire']['reason'] ?? null), 'fig-kiwi-unknown-binary-wire-stop-reason');

$truncatedVarintResult = ( new FigKiwiParser() )->parse(
    SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk(SyntheticFigKiwiFixtureBuilder::wireVarint(8) . "\x80")))
);
$truncatedWire = $truncatedVarintResult['canvas']['chunks'][0]['payload']['wire'] ?? array();
$assert(false === ($truncatedWire['complete'] ?? null), 'fig-kiwi-truncated-varint-incomplete');
$assert(true === ($truncatedWire['truncated'] ?? null), 'fig-kiwi-truncated-varint-flag');
$assert('truncated_varint_value' === ($truncatedWire['reason'] ?? null), 'fig-kiwi-truncated-varint-reason');

$unsupportedWireResult = ( new FigKiwiParser() )->parse(
    SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk(SyntheticFigKiwiFixtureBuilder::wireVarint(11) . 'tail')))
);
$unsupportedWire = $unsupportedWireResult['canvas']['chunks'][0]['payload']['wire'] ?? array();
$assert(false === ($unsupportedWire['complete'] ?? null), 'fig-kiwi-unsupported-wire-incomplete');
$assert(false === ($unsupportedWire['truncated'] ?? null), 'fig-kiwi-unsupported-wire-not-truncated');
$assert('unsupported_wire_type' === ($unsupportedWire['reason'] ?? null), 'fig-kiwi-unsupported-wire-reason');

$multiCandidateFixture = SyntheticFigKiwiFixtureBuilder::figArchive(
    SyntheticFigKiwiFixtureBuilder::canvas(array(
        SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(array('metadata' => array('ignored' => true))),
        SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(SyntheticFigKiwiFixtureBuilder::nodeChangesPayload('First Candidate')),
        SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(SyntheticFigKiwiFixtureBuilder::nodeChangesPayload('Second Candidate')),
    ))
);
$multiCandidateResult = blocks_engine_figma_transformer_transform_file($multiCandidateFixture);
@unlink($multiCandidateFixture);
$multiCandidateHtml = $fileContent($multiCandidateResult, 'index.html');
$assert('success' === ($multiCandidateResult['status'] ?? null), 'fig-kiwi-multiple-candidates-transform-success');
$assert(str_contains($multiCandidateHtml, 'First Candidate First'), 'fig-kiwi-multiple-candidates-renders-first-scenegraph');
$assert(! str_contains($multiCandidateHtml, 'Second Candidate First'), 'fig-kiwi-multiple-candidates-stops-after-first-scenegraph');

$nodeChangesResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'         => 'Node Changes Fixture',
    'NODE_CHANGES' => array(
        '3:1' => array(
            'node' => array(
                'id'                  => '3:1',
                'type'                => 'FRAME',
                'name'                => 'Landing',
                'absoluteBoundingBox' => array('x' => 0, 'y' => 0),
                'children'            => array(
                    array(
                        'id'                  => '3:3',
                        'type'                => 'TEXT',
                        'name'                => 'Body',
                        'characters'          => 'Second',
                        'absoluteBoundingBox' => array('x' => 0, 'y' => 120),
                    ),
                    array(
                        'id'                  => '3:2',
                        'type'                => 'TEXT',
                        'name'                => 'Heading',
                        'characters'          => 'First',
                        'absoluteBoundingBox' => array('x' => 0, 'y' => 20),
                    ),
                    array(
                        'id'    => '3:4',
                        'type'  => 'RECTANGLE',
                        'name'  => 'Photo',
                        'fills' => array(
                            array('type' => 'IMAGE', 'imageRef' => 'image-hash-1'),
                        ),
                    ),
                ),
            ),
        ),
    ),
));

$nodeChangesHtml = (string) ($nodeChangesResult['files'][0]['content'] ?? '');
$scenegraphReport = $nodeChangesResult['source_reports']['figma']['scenegraph'] ?? array();

$assert('success' === ($nodeChangesResult['status'] ?? null), 'node-changes-transform-success');
$assert(4 === ($nodeChangesResult['metrics']['node_count'] ?? null), 'node-changes-node-count');
$assert(2 === ($nodeChangesResult['metrics']['text_node_count'] ?? null), 'node-changes-text-count');
$assert(1 === ($nodeChangesResult['metrics']['asset_reference_count'] ?? null), 'node-changes-asset-count');
$assert('3:1' === ($scenegraphReport['selected_frame_id'] ?? null), 'node-changes-selected-frame');
$assert(false !== strpos($nodeChangesHtml, 'First') && false !== strpos($nodeChangesHtml, 'Second'), 'node-changes-html-text');
$assert(strpos($nodeChangesHtml, 'First') < strpos($nodeChangesHtml, 'Second'), 'node-changes-stable-child-sort');

$layerOrderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'         => 'Layer Order Fixture',
    'NODE_CHANGES' => array(
        'layer:root' => array(
            'node' => array(
                'id'          => 'layer:root',
                'type'        => 'FRAME',
                'name'        => 'Layer root',
                'width'       => 300,
                'height'      => 200,
                'resizeToFit' => true,
                'children'    => array(
                    array(
                        'id'          => 'layer:top',
                        'type'        => 'RECTANGLE',
                        'name'        => 'Top bubble',
                        'width'       => 100,
                        'height'      => 40,
                        'parentIndex' => array('position' => 'b'),
                    ),
                    array(
                        'id'          => 'layer:bottom',
                        'type'        => 'RECTANGLE',
                        'name'        => 'Bottom image',
                        'width'       => 200,
                        'height'      => 120,
                        'parentIndex' => array('position' => 'a'),
                    ),
                ),
            ),
        ),
    ),
));
$layerOrderHtml = $fileContent($layerOrderResult, 'index.html');
$assert(strpos($layerOrderHtml, 'data-figma-node-id="layer:bottom"') < strpos($layerOrderHtml, 'data-figma-node-id="layer:top"'), 'freeform-layer-order-uses-parent-index-position');

$overflowWrapperResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Overflow Wrapper Fixture',
    'nodes' => array(
        array(
            'id'       => 'overflow:root',
            'type'     => 'FRAME',
            'name'     => 'Overflow Root',
            'width'    => 100,
            'height'   => 100,
            'children' => array(
                array(
                    'id'       => 'overflow:wrapper',
                    'type'     => 'FRAME',
                    'name'     => 'Overflow Wrapper',
                    'width'    => 1,
                    'height'   => 10,
                    'children' => array(
                        array(
                            'id'     => 'overflow:child',
                            'type'   => 'RECTANGLE',
                            'name'   => 'Overflow Child',
                            'x'      => 4,
                            'y'      => 2,
                            'width'  => 20,
                            'height' => 12,
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$overflowWrapperCss = $fileContent($overflowWrapperResult, 'style.css');
$assert(str_contains($overflowWrapperCss, '.figma-node-overflow-wrapper-overflow-wrapper{width:1px;height:10px;position:relative}'), 'overflow-wrapper-becomes-positioned-container');
$assert(str_contains($overflowWrapperCss, '.figma-node-overflow-child-overflow-child{width:20px;height:12px;position:absolute;left:4px;top:2px}'), 'overflow-wrapper-child-keeps-local-position');

$objectTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Object Transform Fixture',
    'nodes' => array(
        array(
            'id'        => 'transform:object',
            'type'      => 'RECTANGLE',
            'name'      => 'Object transform',
            'width'     => 10,
            'height'    => 10,
            'transform' => array('m00' => -1, 'm01' => 0, 'm02' => 11, 'm10' => 0, 'm11' => 1, 'm12' => 2),
        ),
    ),
));
$objectTransformCss = $fileContent($objectTransformResult, 'style.css');
$assert(str_contains($objectTransformCss, 'transform:matrix(-1,0,0,1,0,0)'), 'decoded-object-transform-suppresses-position-translation');

$localTransformPositionResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Local Transform Position Fixture',
    'nodes' => array(
        array(
            'id'       => 'local:parent',
            'type'     => 'FRAME',
            'name'     => 'Local parent',
            'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 855, 'm10' => 0, 'm11' => 1, 'm12' => 40),
            'width'    => 500,
            'height'   => 300,
            'children' => array(
                array(
                    'id'        => 'local:child',
                    'type'      => 'RECTANGLE',
                    'name'      => 'Local child',
                    'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 115, 'm10' => 0, 'm11' => 1, 'm12' => 10),
                    'width'     => 100,
                    'height'    => 80,
                ),
                array(
                    'id'        => 'local:sibling',
                    'type'      => 'RECTANGLE',
                    'name'      => 'Local sibling',
                    'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 240, 'm10' => 0, 'm11' => 1, 'm12' => 30),
                    'width'     => 20,
                    'height'    => 20,
                ),
            ),
        ),
    ),
));
$localTransformPositionCss = $fileContent($localTransformPositionResult, 'style.css');
$assert(str_contains($localTransformPositionCss, '.figma-node-local-child-local-child{width:100px;height:80px;position:absolute;left:115px;top:10px}'), 'decoded-local-transform-position-is-not-parent-subtracted');

$fixedFlexResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fixed Flex Fixture',
    'nodes' => array(
        array(
            'id'         => 'flex:row',
            'type'       => 'FRAME',
            'name'       => 'Flex row',
            'width'      => 100,
            'height'     => 40,
            'layoutMode' => 'HORIZONTAL',
            'children'   => array(
                array('id' => 'flex:child-a', 'type' => 'RECTANGLE', 'name' => 'Fixed child A', 'width' => 70, 'height' => 40),
                array('id' => 'flex:child-b', 'type' => 'RECTANGLE', 'name' => 'Fixed child B', 'width' => 70, 'height' => 40),
            ),
        ),
    ),
));
$fixedFlexCss = $fileContent($fixedFlexResult, 'style.css');
$fixedFlexHtml = $fileContent($fixedFlexResult, 'index.html');
// Authored CSS: the two siblings have identical styles, so the fixed (non-shrinking)
// declarations collapse into a single shared, readably-named class referenced by both.
$assert(str_contains($fixedFlexCss, '.fixed-child-a{width:70px;height:40px;flex-shrink:0}'), 'fixed-flex-child-does-not-shrink');
$assert(1 === substr_count($fixedFlexCss, 'width:70px;height:40px;flex-shrink:0'), 'fixed-flex-shared-style-not-duplicated');
$assert(str_contains($fixedFlexHtml, 'class="figma-node-flex-child-a-fixed-child-a fixed-child-a"') && str_contains($fixedFlexHtml, 'class="figma-node-flex-child-b-fixed-child-b fixed-child-a"'), 'fixed-flex-children-share-authored-class');

$fixedRootFlexResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fixed Root Flex Fixture',
    'nodes' => array(
        array(
            'id'                   => 'fixed-root:flex',
            'type'                 => 'FRAME',
            'name'                 => 'Fixed root flex',
            'width'                => 1280,
            'height'               => 100,
            'layoutMode'           => 'VERTICAL',
            'layoutSizingVertical' => 'FIXED',
            'children'             => array(
                array('id' => 'fixed-root:copy', 'type' => 'TEXT', 'name' => 'Fixed root copy', 'text' => 'Root height stays fixed', 'width' => 200, 'height' => 20),
            ),
        ),
    ),
));
$fixedRootFlexCss = $fileContent($fixedRootFlexResult, 'style.css');
$assert(str_contains($fixedRootFlexCss, '.figma-node-fixed-root-flex-fixed-root-flex{width:100%;max-width:1280px;margin-left:auto;margin-right:auto;height:100px;display:flex;flex-direction:column}'), 'fixed-root-flex-emits-fixed-height');
$assert(! str_contains($fixedRootFlexCss, '.figma-node-fixed-root-flex-fixed-root-flex{width:1280px;min-height:100px'), 'fixed-root-flex-does-not-emit-min-height');

$fixedPaddingClampResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fixed Padding Clamp Fixture',
    'nodes' => array(
        array(
            'id'                   => 'padding:frame',
            'type'                 => 'FRAME',
            'name'                 => 'Impossible fixed padding',
            'width'                => 1280,
            'height'               => 100,
            'layoutMode'           => 'VERTICAL',
            'layoutSizingVertical' => 'FIXED',
            'paddingTop'           => 80,
            'paddingBottom'        => 80,
            'children'             => array(
                array('id' => 'padding:copy', 'type' => 'TEXT', 'name' => 'Padded copy', 'text' => 'Padding is clamped', 'width' => 200, 'height' => 20),
            ),
        ),
    ),
));
$fixedPaddingClampCss = $fileContent($fixedPaddingClampResult, 'style.css');
$fixedPaddingClampCopy = $findVisualNode($fixedPaddingClampResult, 'padding:copy');
$assert(str_contains($fixedPaddingClampCss, '.figma-node-padding-frame-impossible-fixed-padding{width:100%;max-width:1280px;margin-left:auto;margin-right:auto;height:100px;display:flex;flex-direction:column;padding-top:50px;padding-bottom:50px}'), 'fixed-padding-clamped-css');
$assert(50.0 === ($fixedPaddingClampCopy['rect']['y'] ?? null), 'fixed-padding-clamped-visual-map');

$stylePaintResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Style Paint Fixture',
    'nodes' => array(
        array(
            'guid'       => array('sessionID' => 10, 'localID' => 1),
            'type'       => 'ROUNDED_RECTANGLE',
            'name'       => 'Primary-500',
            'styleType'  => 'FILL',
            'fillPaints' => array(
                array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.8, 'b' => 0.5, 'a' => 1)),
            ),
        ),
        array(
            'id'             => 'style:button',
            'type'           => 'RECTANGLE',
            'name'           => 'Styled button',
            'width'          => 100,
            'height'         => 40,
            'styleIdForFill' => array('guid' => array('sessionID' => 10, 'localID' => 1)),
            'fillPaints'     => array(
                array('type' => 'SOLID', 'color' => array('r' => 0.5, 'g' => 0.2, 'b' => 0.1, 'a' => 1)),
            ),
        ),
    ),
));
$stylePaintCss = $fileContent($stylePaintResult, 'style.css');
$assert(str_contains($stylePaintCss, '.figma-node-style-button-styled-button{width:100px;height:40px;background:#1acc80}'), 'style-paint-overrides-stale-inline-fill');

$outsideStrokeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Outside Stroke Fixture',
    'nodes' => array(
        array(
            'id'          => 'stroke:outside',
            'type'        => 'RECTANGLE',
            'name'        => 'Outside stroke image',
            'width'       => 100,
            'height'      => 80,
            'strokeAlign' => 'OUTSIDE',
            'strokeWeight'=> 8,
            'strokes'     => array(
                array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1)),
            ),
        ),
    ),
));
$outsideStrokeCss = $fileContent($outsideStrokeResult, 'style.css');
$assert(str_contains($outsideStrokeCss, '.figma-node-stroke-outside-outside-stroke-image{width:100px;height:80px;box-shadow:0 0 0 8px #ffffff}'), 'outside-stroke-emits-non-shrinking-shadow');
$assert(! str_contains($outsideStrokeCss, 'border:8px solid #ffffff'), 'outside-stroke-does-not-shrink-border-box');

// Stroke geometry (#328): an INSIDE stroke must render at the design weight (3px),
// not the stale 1px default, and stay inside the box via box-sizing.
$insideStrokeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Inside Stroke Fixture',
    'nodes' => array(
        array(
            'id'           => 'stroke:inside',
            'type'         => 'RECTANGLE',
            'name'         => 'Inside stroke box',
            'width'        => 120,
            'height'       => 90,
            'strokeAlign'  => 'INSIDE',
            'strokeWeight' => 3,
            'strokes'      => array(
                array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)),
            ),
        ),
    ),
));
$insideStrokeCss = $fileContent($insideStrokeResult, 'style.css');
$assert(str_contains($insideStrokeCss, '.figma-node-stroke-inside-inside-stroke-box{width:120px;height:90px;border:3px solid #000000;box-sizing:border-box}'), 'inside-stroke-emits-design-width-border');
$assert(! str_contains($insideStrokeCss, 'border:1px solid'), 'inside-stroke-does-not-default-to-1px');

// A non-empty dashPattern degrades to a dashed border at the design weight.
$dashedStrokeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Dashed Stroke Fixture',
    'nodes' => array(
        array(
            'id'           => 'stroke:dashed',
            'type'         => 'RECTANGLE',
            'name'         => 'Dashed stroke box',
            'width'        => 80,
            'height'       => 60,
            'strokeAlign'  => 'INSIDE',
            'strokeWeight' => 2,
            'dashPattern'  => array(4, 2),
            'strokes'      => array(
                array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)),
            ),
        ),
    ),
));
$dashedStrokeCss = $fileContent($dashedStrokeResult, 'style.css');
$assert(str_contains($dashedStrokeCss, 'border-style:dashed'), 'dashed-stroke-emits-dashed-border-style');
$assert(str_contains($dashedStrokeCss, 'border-width:2px'), 'dashed-stroke-emits-design-width');

$gradientPaintResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Gradient Paint Fixture',
    'nodes' => array(
        array(
            'id'     => 'gradient:linear',
            'type'   => 'RECTANGLE',
            'name'   => 'Linear gradient',
            'width'  => 100,
            'height' => 80,
            'fills'  => array(
                array(
                    'type'          => 'GRADIENT_LINEAR',
                    'gradientStops' => array(
                        array('position' => 0, 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:linear-h',
            'type'   => 'RECTANGLE',
            'name'   => 'Horizontal gradient',
            'width'  => 120,
            'height' => 80,
            'fills'  => array(
                array(
                    'type'              => 'GRADIENT_LINEAR',
                    // Identity gradientTransform: the gradient runs along the
                    // shape's x-axis, i.e. left-to-right => CSS 90deg.
                    'gradientTransform' => array(
                        array(1, 0, 0),
                        array(0, 1, 0),
                    ),
                    'gradientStops'     => array(
                        array('position' => 0, 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:linear-v',
            'type'   => 'RECTANGLE',
            'name'   => 'Vertical gradient',
            'width'  => 110,
            'height' => 80,
            'fills'  => array(
                array(
                    'type'              => 'GRADIENT_LINEAR',
                    // A real top-to-bottom Figma transform; the formula must
                    // still resolve this to CSS 180deg.
                    'gradientTransform' => array(
                        array(0, 1, 0),
                        array(-1, 0, 1),
                    ),
                    'gradientStops'     => array(
                        array('position' => 0, 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:radial',
            'type'   => 'RECTANGLE',
            'name'   => 'Radial gradient',
            'width'  => 90,
            'height' => 70,
            'fills'  => array(
                array(
                    'type'          => 'GRADIENT_RADIAL',
                    'opacity'       => 0.5,
                    'gradientStops' => array(
                        array('position' => 0.25, 'color' => array('r' => 0, 'g' => 1, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'           => 'gradient:stroke',
            'type'         => 'RECTANGLE',
            'name'         => 'Gradient stroke',
            'width'        => 70,
            'height'       => 60,
            'strokeWeight' => 3,
            'strokes'      => array(
                array(
                    'type'          => 'GRADIENT_LINEAR',
                    'gradientStops' => array(
                        array('position' => 0, 'color' => array('r' => 1, 'g' => 1, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 1, 'g' => 0, 'b' => 1, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:angular',
            'type'   => 'RECTANGLE',
            'name'   => 'Angular gradient',
            'width'  => 100,
            'height' => 100,
            'fills'  => array(
                array(
                    'type'              => 'GRADIENT_ANGULAR',
                    // Identity gradientTransform: the angular seam (t=0) runs
                    // along the shape's +x axis (3 o'clock) => CSS conic
                    // `from 90deg`, centered at 50% 50%.
                    'gradientTransform' => array(
                        array(1, 0, 0),
                        array(0, 1, 0),
                    ),
                    'gradientStops'     => array(
                        array('position' => 0, 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:angular-top',
            'type'   => 'RECTANGLE',
            'name'   => 'Angular gradient top',
            'width'  => 100,
            'height' => 100,
            'fills'  => array(
                array(
                    'type'              => 'GRADIENT_ANGULAR',
                    // A transform whose canonical +u axis maps to the shape's
                    // -y (up) direction: the seam starts at the top (12 o'clock)
                    // => CSS conic `from 0deg`, still centered at 50% 50%.
                    'gradientTransform' => array(
                        array(0, -1, 1),
                        array(1, 0, 0),
                    ),
                    'gradientStops'     => array(
                        array('position' => 0, 'color' => array('r' => 0, 'g' => 1, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:angular-default',
            'type'   => 'RECTANGLE',
            'name'   => 'Angular gradient default',
            'width'  => 100,
            'height' => 100,
            'fills'  => array(
                array(
                    // No gradientTransform: emit a deterministic centered conic
                    // gradient with its seam at the top.
                    'type'          => 'GRADIENT_ANGULAR',
                    'gradientStops' => array(
                        array('position' => 0, 'color' => array('r' => 1, 'g' => 1, 'b' => 0, 'a' => 1)),
                        array('position' => 1, 'color' => array('r' => 1, 'g' => 0, 'b' => 1, 'a' => 1)),
                    ),
                ),
            ),
        ),
        array(
            'id'     => 'gradient:malformed',
            'type'   => 'RECTANGLE',
            'name'   => 'Malformed gradient',
            'width'  => 50,
            'height' => 40,
            'fills'  => array(
                array('type' => 'GRADIENT_DIAMOND'),
            ),
        ),
    ),
));
$gradientPaintCss = $fileContent($gradientPaintResult, 'style.css');
$gradientDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $gradientPaintResult['diagnostics'] ?? array()
);
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-linear-linear-gradient{width:100px;height:80px;background:linear-gradient(180deg,#ff0000 0%,#0000ff 100%)}'), 'linear-gradient-background-emits');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-linear-h-horizontal-gradient{width:120px;height:80px;background:linear-gradient(90deg,#ff0000 0%,#0000ff 100%)}'), 'linear-gradient-horizontal-transform-emits-90deg');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-linear-v-vertical-gradient{width:110px;height:80px;background:linear-gradient(180deg,#ff0000 0%,#0000ff 100%)}'), 'linear-gradient-vertical-transform-emits-180deg');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-radial-radial-gradient{width:90px;height:70px;background:radial-gradient(circle,rgba(0,255,0,0.5) 25%,rgba(0,0,0,0.5) 100%)}'), 'radial-gradient-background-emits');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-stroke-gradient-stroke{width:70px;height:60px;border:3px solid transparent;border-image:linear-gradient(180deg,#ffff00 0%,#ff00ff 100%) 1}'), 'linear-gradient-stroke-emits-border-image');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-angular-angular-gradient{width:100px;height:100px;background:conic-gradient(from 90deg at 50% 50%,#ff0000 0%,#0000ff 100%)}'), 'angular-gradient-identity-transform-emits-from-90deg');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-angular-top-angular-gradient-top{width:100px;height:100px;background:conic-gradient(from 0deg at 50% 50%,#00ff00 0%,#000000 100%)}'), 'angular-gradient-top-seam-transform-emits-from-0deg');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-angular-default-angular-gradient-default{width:100px;height:100px;background:conic-gradient(from 0deg at 50% 50%,#ffff00 0%,#ff00ff 100%)}'), 'angular-gradient-no-transform-emits-centered-default');
$assert(in_array('unsupported_figma_paint_type', $gradientDiagnosticCodes, true), 'unsupported-malformed-gradient-diagnostic');

$metadataResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Text And Paint Metadata',
    'nodes' => array(
        array(
            'id'           => '4:1',
            'type'         => 'FRAME',
            'name'         => 'Metadata frame',
            'opacity'      => 0.75,
            'cornerRadius' => 12,
            'fills'        => array(
                array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.6), 'opacity' => 0.5),
                array('type' => 'GRADIENT_LINEAR'),
            ),
            'strokes'      => array(
                array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0)),
            ),
            'strokeWeight' => 2,
            'effects'      => array(
                array('type' => 'DROP_SHADOW'),
            ),
            'children'     => array(
                array(
                    'id'                 => '4:2',
                    'type'               => 'TEXT',
                    'name'               => 'Mixed text',
                    'characters'         => 'Hello World',
                    'style'              => array(
                        'fontFamily'         => 'Example Sans',
                        'fontSize'           => 20,
                        'fontWeight'         => 600,
                        'lineHeightPercent'  => 125,
                        'letterSpacing'      => 0.5,
                        'textAlignHorizontal'=> 'CENTER',
                        'textAlignVertical'  => 'TOP',
                        'textDecoration'     => 'UNDERLINE',
                    ),
                    'fills'              => array(
                        array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.5, 'b' => 0), 'opacity' => 0.8),
                    ),
                    'styledTextSegments' => array(
                        array('characters' => 'Hello ', 'style' => array('fontWeight' => 400)),
                        array('characters' => 'World', 'style' => array('fontWeight' => 700, 'textDecoration' => 'UNDERLINE')),
                    ),
                ),
                array(
                    'id'                => '4:3',
                    'type'              => 'RECTANGLE',
                    'name'              => 'Uneven radius',
                    'topLeftRadius'     => 4,
                    'topRightRadius'    => 8,
                    'bottomRightRadius' => 12,
                    'bottomLeftRadius'  => 16,
                    'fills'             => array(
                        array('type' => 'GRADIENT_RADIAL'),
                    ),
                ),
                array(
                    'id'         => '4:4',
                    'type'       => 'TEXT',
                    'name'       => 'Raw line height text',
                    'characters' => 'Raw line height',
                    'fontName'   => array('family' => 'Example Sans', 'style' => 'SemiBold'),
                    'fontSize'   => 18,
                    'lineHeight' => array('units' => 'RAW', 'value' => 1.15),
                ),
                array(
                    'id'            => '4:5',
                    'type'          => 'TEXT',
                    'name'          => 'WP Cloud text metrics',
                    'characters'    => 'WordPress with no worries',
                    'fontName'      => array('family' => 'DM Sans', 'style' => 'Bold'),
                    'fontSize'      => 80,
                    'lineHeight'    => array('units' => 'RAW', 'value' => 1.05),
                    'letterSpacing' => array('units' => 'PERCENT', 'value' => -2),
                ),
                array(
                    'id'         => '4:6',
                    'type'       => 'TEXT',
                    'name'       => 'Zero line height text',
                    'characters' => 'Navigation item',
                    'fontName'   => array('family' => 'Example Sans', 'style' => 'Regular'),
                    'fontSize'   => 16,
                    'lineHeight' => array('units' => 'RAW', 'value' => 0),
                ),
            ),
        ),
    ),
));
$metadataWithFontCssResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Font CSS Fixture',
    'nodes' => array(
        array(
            'id'         => 'font:1',
            'type'       => 'TEXT',
            'name'       => 'Font text',
            'characters' => 'Font CSS',
            'style'      => array('fontFamily' => 'Example Sans', 'fontSize' => 20),
        ),
    ),
), array('font_css' => '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}'));

$metadataHtml = $fileContent($metadataResult, 'index.html');
$metadataCss = $fileContent($metadataResult, 'style.css');
$metadataWithFontCss = $fileContent($metadataWithFontCssResult, 'style.css');
$metadataDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $metadataResult['diagnostics'] ?? array()
);

$assert(str_contains($metadataHtml, '<span style="font-weight:400">Hello </span><span style="font-weight:700;text-decoration:underline">World</span>'), 'styled-text-segments-emit');
$assert(str_contains($metadataCss, 'p,h1,h2,h3,h4,h5,h6{margin:0}'), 'text-elements-reset-default-margins');
$assert(str_contains($metadataCss, '.figma-node-4-1-metadata-frame{position:relative;background:rgba(51,102,153,0.5);opacity:0.75;border-radius:12px;border:2px solid #000000;box-shadow:0px 0px 0px 0px rgba(0,0,0,0.25)}'), 'normalized-frame-paint-box-style');
$assert(str_contains($metadataCss, '.figma-node-4-2-mixed-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:20px;font-weight:600;line-height:125%;letter-spacing:0.5px;color:rgba(255,128,0,0.8);text-align:center;vertical-align:top;text-decoration:underline}'), 'normalized-text-style');
$assert(str_contains($metadataCss, '.figma-node-4-3-uneven-radius{position:absolute;border-top-left-radius:4px;border-top-right-radius:8px;border-bottom-right-radius:12px;border-bottom-left-radius:16px}'), 'individual-radius-style');
$assert(str_contains($metadataCss, '.figma-node-4-4-raw-line-height-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px;font-weight:600;line-height:1.15}'), 'font-style-weight-and-raw-line-height');
$assert(str_contains($metadataCss, '.figma-node-4-5-wp-cloud-text-metrics{position:absolute;font-family:"DM Sans", sans-serif;font-size:80px;font-weight:700;line-height:1.05;letter-spacing:-0.02em}'), 'wp-cloud-text-metrics-style');
$assert(str_contains($metadataCss, '.figma-node-4-6-zero-line-height-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:16px;font-weight:400}') && ! str_contains($metadataCss, 'line-height:0'), 'zero-line-height-omitted');
$assert(in_array('unsupported_figma_paint_type', $metadataDiagnosticCodes, true), 'unsupported-paint-diagnostic');
$assert(! in_array('unsupported_figma_effect_type', $metadataDiagnosticCodes, true), 'supported-effect-no-diagnostic');
$assert(in_array('font_css_missing_for_source_font', $metadataDiagnosticCodes, true), 'missing-font-css-diagnostic');
$assert(str_starts_with($metadataWithFontCss, '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}'), 'font-css-prepended-when-supplied');
$assert(array('Example Sans') === ($metadataWithFontCssResult['source_reports']['figma']['html']['font_families'] ?? null), 'font-family-inventory-reports-source-fonts');
$metadataFontUsage = $metadataResult['source_reports']['figma']['html']['font_usage'] ?? array();
$compiledSiteFontUsage = $metadataResult['source_reports']['compiled_site']['theme']['font_usage'] ?? array();
$assert(array('DM Sans', 'Example Sans') === array_column(is_array($metadataFontUsage) ? $metadataFontUsage : array(), 'family'), 'font-usage-reports-source-families');
$assert(array(700) === ($metadataFontUsage[0]['weights'] ?? null), 'font-usage-reports-source-dm-sans-weights');
$assert(array(400, 600) === ($metadataFontUsage[1]['weights'] ?? null), 'font-usage-reports-source-example-sans-weights');
$assert(1 === ($metadataFontUsage[0]['text_node_count'] ?? null), 'font-usage-reports-source-dm-sans-node-count');
$assert(3 === ($metadataFontUsage[1]['text_node_count'] ?? null), 'font-usage-reports-source-example-sans-node-count');
$assert(2 === ($metadataFontUsage[1]['weight_counts']['600'] ?? null), 'font-usage-reports-source-example-sans-weight-count');
$assert(array_column(is_array($metadataFontUsage) ? $metadataFontUsage : array(), 'family') === array_column(is_array($compiledSiteFontUsage) ? $compiledSiteFontUsage : array(), 'family'), 'compiled-site-theme-promotes-figma-font-usage-families');
$assert(true === ($metadataWithFontCssResult['source_reports']['figma']['html']['font_css_supplied'] ?? null), 'font-css-supplied-report');
$metadataTransformDiagnostics = $metadataResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$metadataWithFontCssTransformDiagnostics = $metadataWithFontCssResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert(array('DM Sans', 'Example Sans') === ($metadataTransformDiagnostics['fonts']['families'] ?? null), 'transform-diagnostics-font-families');
// DM Sans is a known Google Fonts family so it resolves to a CDN @font-face import,
// while the fictional "Example Sans" stays unresolved and actionable for an operator.
$assert(true === ($metadataTransformDiagnostics['fonts']['materialized'] ?? null), 'transform-diagnostics-font-materialized-via-cdn');
$assert(str_contains($metadataCss, "@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@700&display=swap');"), 'cdn-font-import-emitted');
$assert(str_starts_with(ltrim($metadataCss), '/*') || str_starts_with($metadataCss, '@import'), 'cdn-font-import-hoisted-to-top');
$assert(array('Example Sans') === ($metadataTransformDiagnostics['fonts']['missing_css'] ?? null), 'transform-diagnostics-font-missing-css');
$assert(array('DM Sans') === ($metadataTransformDiagnostics['fonts']['resolved_css'] ?? null), 'transform-diagnostics-font-resolved-css');
$assert(array('DM Sans') === ($metadataTransformDiagnostics['fonts']['cdn_families'] ?? null), 'transform-diagnostics-font-cdn-families');
$fontCoverage = $metadataTransformDiagnostics['fonts']['coverage'] ?? array();
$coverageByFamily = array();
foreach ( is_array($fontCoverage) ? $fontCoverage : array() as $coverageEntry ) {
    $coverageByFamily[(string) ($coverageEntry['family'] ?? '')] = $coverageEntry;
}
$assert('cdn_google_fonts' === ($coverageByFamily['DM Sans']['resolution'] ?? null) && true === ($coverageByFamily['DM Sans']['resolved'] ?? null), 'font-coverage-dm-sans-resolved-via-cdn');
$assert(false === ($coverageByFamily['DM Sans']['needs_operator_font'] ?? null) && str_contains((string) ($coverageByFamily['DM Sans']['source_url'] ?? ''), 'fonts.googleapis.com'), 'font-coverage-dm-sans-cdn-source-url');
$assert('unresolved' === ($coverageByFamily['Example Sans']['resolution'] ?? null) && true === ($coverageByFamily['Example Sans']['needs_operator_font'] ?? null), 'font-coverage-example-sans-needs-operator-font');
$assert('"Example Sans", sans-serif' === ($coverageByFamily['Example Sans']['fallback_stack'] ?? null), 'font-coverage-example-sans-fallback-stack');
$missingFontCssSignal = $artifactQualitySignal($metadataResult, 'font_css_missing');
$assert('warning' === ($missingFontCssSignal['severity'] ?? null), 'font-css-missing-quality-warning');
$assert('needs_review' === ($metadataTransformDiagnostics['artifact_quality']['status'] ?? null), 'font-css-missing-quality-needs-review');
$assert('warn' === ($metadataTransformDiagnostics['artifact_quality']['quality_status'] ?? null), 'font-css-missing-quality-status-warn');
$assert(1 === ($missingFontCssSignal['count'] ?? null), 'font-css-missing-quality-count');
$assert(! in_array('font_css_missing', $artifactQualitySignalCodes($metadataWithFontCssResult), true), 'font-css-supplied-suppresses-quality-warning');
$assert(true === ($metadataWithFontCssTransformDiagnostics['fonts']['materialized'] ?? null), 'transform-diagnostics-font-materialized-with-css');
$styleDiagnostics = $metadataResult['source_reports']['figma']['html']['node_style_diagnostics'] ?? array();
$mixedTextStyleDiagnostic = null;
$frameStyleDiagnostic = null;
foreach ( $styleDiagnostics as $styleDiagnostic ) {
    if ( '4:2' === ($styleDiagnostic['node']['id'] ?? null) ) {
        $mixedTextStyleDiagnostic = $styleDiagnostic;
    }
    if ( '4:1' === ($styleDiagnostic['node']['id'] ?? null) ) {
        $frameStyleDiagnostic = $styleDiagnostic;
    }
}
$assert(null !== $mixedTextStyleDiagnostic, 'node-style-diagnostics-text-node-present');
$assert('"Example Sans", sans-serif' === ($mixedTextStyleDiagnostic['expected']['font_family'] ?? null), 'node-style-diagnostics-expected-font-family');
$assert('"Example Sans", sans-serif' === ($mixedTextStyleDiagnostic['emitted']['font_family'] ?? null), 'node-style-diagnostics-emitted-font-family');
$assert('20px' === ($mixedTextStyleDiagnostic['expected']['font_size'] ?? null), 'node-style-diagnostics-expected-font-size');
$assert('20px' === ($mixedTextStyleDiagnostic['emitted']['font_size'] ?? null), 'node-style-diagnostics-emitted-font-size');
$assert('rgba(255,128,0,0.8)' === ($mixedTextStyleDiagnostic['expected']['text_color'] ?? null), 'node-style-diagnostics-expected-text-color');
$assert('rgba(255,128,0,0.8)' === ($mixedTextStyleDiagnostic['emitted']['text_color'] ?? null), 'node-style-diagnostics-emitted-text-color');
$assert(null !== $frameStyleDiagnostic, 'node-style-diagnostics-frame-node-present');
$assert('rgba(51,102,153,0.5)' === ($frameStyleDiagnostic['expected']['background'] ?? null), 'node-style-diagnostics-expected-background');
$assert('rgba(51,102,153,0.5)' === ($frameStyleDiagnostic['emitted']['background'] ?? null), 'node-style-diagnostics-emitted-background');

// Kiwi-format per-corner radii (the `.fig` ingestion path). Decoded archives carry
// the Kiwi field names (`rectangleTopLeftCornerRadius`) alongside a uniform
// `cornerRadius`, where the REST scenegraph would carry `topLeftRadius`. The
// normalizer must read the Kiwi names and let per-corner values win over the
// uniform radius so a mixed node (top rounded, bottom square) keeps its shape
// instead of collapsing to a uniform radius or a square.
$kiwiRadiusResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Corner Radius',
    'nodes' => array(
        array(
            'id'       => '5:1',
            'type'     => 'FRAME',
            'name'     => 'Kiwi radius frame',
            'width'    => 200,
            'height'   => 200,
            'children' => array(
                array(
                    'id'                               => '5:2',
                    'type'                             => 'RECTANGLE',
                    'name'                             => 'Kiwi corner radius',
                    'width'                            => 160,
                    'height'                           => 90,
                    // Uniform value is intentionally wrong; per-corner must override it.
                    'cornerRadius'                     => 99,
                    'rectangleTopLeftCornerRadius'     => 8,
                    'rectangleTopRightCornerRadius'    => 8,
                    'rectangleBottomRightCornerRadius' => 0,
                    'rectangleBottomLeftCornerRadius'  => 0,
                ),
            ),
        ),
    ),
));

// Figma `textCase` enum → CSS text-transform / font-variant, and `paragraphSpacing`
// surfaced as an info diagnostic because a single-element text node cannot carry
// per-paragraph margins.
$textCaseResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Text Case And Paragraph Spacing',
    'nodes' => array(
        array(
            'id'       => 'tc:1',
            'type'     => 'FRAME',
            'name'     => 'Text case frame',
            'children' => array(
                array(
                    'id'         => 'tc:2',
                    'type'       => 'TEXT',
                    'name'       => 'Upper text',
                    'characters' => 'shout this',
                    'style'      => array(
                        'fontFamily' => 'Example Sans',
                        'fontSize'   => 18,
                        'textCase'   => 'UPPER',
                    ),
                ),
                array(
                    'id'         => 'tc:3',
                    'type'       => 'TEXT',
                    'name'       => 'Lower text',
                    'characters' => 'QUIET This',
                    'style'      => array(
                        'fontFamily' => 'Example Sans',
                        'fontSize'   => 18,
                        'textCase'   => 'LOWER',
                    ),
                ),
                array(
                    'id'         => 'tc:4',
                    'type'       => 'TEXT',
                    'name'       => 'Title text',
                    'characters' => 'a nice heading',
                    'style'      => array(
                        'fontFamily' => 'Example Sans',
                        'fontSize'   => 18,
                        'textCase'   => 'TITLE',
                    ),
                ),
                array(
                    'id'         => 'tc:5',
                    'type'       => 'TEXT',
                    'name'       => 'Small caps forced text',
                    'characters' => 'small caps forced',
                    'style'      => array(
                        'fontFamily' => 'Example Sans',
                        'fontSize'   => 18,
                        'textCase'   => 'SMALL_CAPS_FORCED',
                    ),
                ),
                array(
                    'id'         => 'tc:6',
                    'type'       => 'TEXT',
                    'name'       => 'Original case text',
                    'characters' => 'Leave Me Alone',
                    'style'      => array(
                        'fontFamily' => 'Example Sans',
                        'fontSize'   => 18,
                        'textCase'   => 'ORIGINAL',
                    ),
                ),
                array(
                    'id'         => 'tc:7',
                    'type'       => 'TEXT',
                    'name'       => 'Multi paragraph text',
                    'characters' => "First paragraph.\nSecond paragraph.",
                    'style'      => array(
                        'fontFamily'        => 'Example Sans',
                        'fontSize'          => 18,
                        'paragraphSpacing'  => 24,
                    ),
                ),
            ),
        ),
    ),
));
$kiwiRadiusCss = $fileContent($kiwiRadiusResult, 'style.css');
$assert(str_contains($kiwiRadiusCss, '.figma-node-5-2-kiwi-corner-radius{width:160px;height:90px;border-top-left-radius:8px;border-top-right-radius:8px;border-bottom-right-radius:0px;border-bottom-left-radius:0px}'), 'kiwi-per-corner-radius-style');
$assert(! str_contains($kiwiRadiusCss, 'border-radius:99px'), 'kiwi-per-corner-radius-overrides-uniform');

$textCaseCss = $fileContent($textCaseResult, 'style.css');
$textCaseDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $textCaseResult['diagnostics'] ?? array()
);
$assert(str_contains($textCaseCss, '.figma-node-tc-2-upper-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px;text-transform:uppercase}'), 'text-case-upper-text-transform');
$assert(str_contains($textCaseCss, 'text-transform:lowercase'), 'text-case-lower-text-transform');
$assert(str_contains($textCaseCss, 'text-transform:capitalize'), 'text-case-title-text-transform');
$assert(str_contains($textCaseCss, '.figma-node-tc-5-small-caps-forced-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px;text-transform:uppercase;font-variant:small-caps}'), 'text-case-small-caps-forced');
// ORIGINAL text case emits no text-transform. With paragraphSpacing now applied
// by splitting (no white-space:pre-line), tc:7's box style matches tc:6's, so the
// emitter dedupes them into one shared rule — assert on the un-transformed
// declaration body and that tc:6 carries no transform-bearing rule of its own.
$assert(str_contains($textCaseCss, '{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px}'), 'text-case-original-no-transform');
$assert(! str_contains($textCaseCss, '.figma-node-tc-6-original-case-text{'), 'text-case-original-deduped-into-shared-rule');
// `paragraphSpacing` is now applied by splitting the multi-paragraph node into
// per-paragraph boxes (completing the path started in #318), so the
// `paragraph_spacing_not_applied` diagnostic must no longer fire for tc:7.
$textCaseParagraphDiagnostic = null;
foreach ( $textCaseResult['diagnostics'] ?? array() as $diagnostic ) {
    if ( 'paragraph_spacing_not_applied' === ($diagnostic['code'] ?? null) ) {
        $textCaseParagraphDiagnostic = $diagnostic;
        break;
    }
}
$assert(null === $textCaseParagraphDiagnostic, 'paragraph-spacing-diagnostic-dropped-when-applied');
$assert(! str_contains($textCaseCss, 'paragraph-spacing') && ! str_contains($textCaseCss, 'paragraph_spacing'), 'paragraph-spacing-not-emitted-as-css');

// The two paragraphs render as separate block boxes; the first carries the
// 24px paragraph spacing as a margin-bottom, the last carries none. The split
// node also drops the single-element white-space:pre-line fallback.
$textCaseHtml = $fileContent($textCaseResult, 'index.html');
$assert(str_contains($textCaseHtml, '<span style="display:block;margin-bottom:24px">First paragraph.</span><span style="display:block">Second paragraph.</span>'), 'paragraph-spacing-split-into-margin-boxes');
$assert(! str_contains($textCaseCss, 'white-space:pre-line'), 'paragraph-spacing-split-drops-pre-line');

// Node-level blendMode → CSS mix-blend-mode. A non-default Figma blend mode
// (MULTIPLY) must surface as `mix-blend-mode:multiply`, while the default
// compositing modes (NORMAL / PASS_THROUGH) emit no mix-blend-mode at all.
$blendModeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Blend Mode Fixture',
    'nodes' => array(
        array(
            'id'        => 'blend:1',
            'type'      => 'FRAME',
            'name'      => 'Multiply layer',
            'width'     => 100,
            'height'    => 80,
            'blendMode' => 'MULTIPLY',
        ),
        array(
            'id'        => 'blend:2',
            'type'      => 'FRAME',
            'name'      => 'Normal layer',
            'width'     => 100,
            'height'    => 80,
            'blendMode' => 'NORMAL',
        ),
        array(
            'id'        => 'blend:3',
            'type'      => 'FRAME',
            'name'      => 'Pass through layer',
            'width'     => 100,
            'height'    => 80,
            'blendMode' => 'PASS_THROUGH',
        ),
    ),
));
$blendModeCss = $fileContent($blendModeResult, 'style.css');
$assert(str_contains($blendModeCss, '.figma-node-blend-1-multiply-layer{') && str_contains($blendModeCss, 'mix-blend-mode:multiply'), 'node-blend-mode-multiply-emits');
$assert(1 === substr_count($blendModeCss, 'mix-blend-mode'), 'node-blend-mode-normal-and-pass-through-omit');

// Inline character-level style overrides (characterStyleOverrides + styleOverrideTable).
//
// The Figma API encodes per-character style overrides as a parallel array of integer
// IDs (`characterStyleOverrides`) and a lookup table (`styleOverrideTable`). When
// characters share the same override ID they collapse into one run. The normalizer
// converts these into the same `segments` contract used by `styledTextSegments` so
// the emitter can wrap differing characters in minimal `<span style="...">` tags.
$inlineTextStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Inline Text Style Fixture',
    'nodes' => array(
        array(
            'id'       => 'its:1',
            'type'     => 'FRAME',
            'name'     => 'Inline text frame',
            'width'    => 1200,
            'height'   => 400,
            'children' => array(
                // All overrides are 0 — no inline spans expected.
                array(
                    'id'                      => 'its:2',
                    'type'                    => 'TEXT',
                    'name'                    => 'Single style text',
                    'characters'              => 'Plain text node',
                    'style'                   => array(
                        'fontFamily' => 'Inter',
                        'fontWeight' => 400,
                        'fontSize'   => 16,
                        'fills'      => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                    ),
                    // 15 characters all mapping to base style.
                    'characterStyleOverrides' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                    'styleOverrideTable'      => array(
                        '1' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)))),
                    ),
                ),
                // "Hello blue " (11 chars) in base black, "world" (5 chars) in blue.
                array(
                    'id'                      => 'its:3',
                    'type'                    => 'TEXT',
                    'name'                    => 'Two color text',
                    'characters'              => 'Hello blue world',
                    'style'                   => array(
                        'fontFamily' => 'Inter',
                        'fontWeight' => 400,
                        'fontSize'   => 16,
                        'fills'      => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                    ),
                    'characterStyleOverrides' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1),
                    'styleOverrideTable'      => array(
                        '1' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)))),
                    ),
                ),
                // "Bold" (4 chars) at weight 700, " plain text" (11 chars) at base weight 400.
                array(
                    'id'                      => 'its:4',
                    'type'                    => 'TEXT',
                    'name'                    => 'Mixed weight text',
                    'characters'              => 'Bold plain text',
                    'style'                   => array(
                        'fontFamily' => 'Inter',
                        'fontWeight' => 400,
                        'fontSize'   => 16,
                    ),
                    'characterStyleOverrides' => array(1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                    'styleOverrideTable'      => array(
                        '1' => array('fontWeight' => 700),
                    ),
                ),
            ),
        ),
    ),
));
$inlineTextStyleHtml = $fileContent($inlineTextStyleResult, 'index.html');

// Single-style: all overrides resolve to 0 so no <span> wrapper is emitted.
$assert(str_contains($inlineTextStyleHtml, '>Plain text node</p>'), 'inline-style-single-style-no-span');

// Two-color: only the "world" run differs in fill color — it gets a color span.
$assert(str_contains($inlineTextStyleHtml, 'Hello blue <span style="color:#0000ff">world</span>'), 'inline-style-two-color-spans');

// Mixed-weight: only "Bold" differs in font-weight — it gets a font-weight span.
$assert(str_contains($inlineTextStyleHtml, '<span style="font-weight:700">Bold</span> plain text'), 'inline-style-mixed-weight-spans');

// Paragraph splitting must preserve inline override spans inside the correct
// paragraph, and single-paragraph nodes must not gain a wrapper (#318 follow-up).
//
// "Bold intro\nplain rest" (21 chars): the first 4 characters ("Bold") map to a
// weight-700 override, the rest to the base style. The `\n` (index 10) is the
// paragraph boundary, so the bold span belongs to the first paragraph and only
// the first paragraph carries the 12px margin-bottom.
$paragraphSplitResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Paragraph Split Fixture',
    'nodes' => array(
        array(
            'id'       => 'psplit:1',
            'type'     => 'FRAME',
            'name'     => 'Paragraph split frame',
            'width'    => 1200,
            'height'   => 400,
            'children' => array(
                // Multi-paragraph node with an inline weight override in paragraph 1.
                array(
                    'id'                      => 'psplit:2',
                    'type'                    => 'TEXT',
                    'name'                    => 'Styled multi paragraph',
                    'characters'              => "Bold intro\nplain rest",
                    'style'                   => array(
                        'fontFamily'       => 'Inter',
                        'fontWeight'       => 400,
                        'fontSize'         => 16,
                        'paragraphSpacing' => 12,
                    ),
                    'characterStyleOverrides' => array(1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                    'styleOverrideTable'      => array(
                        '1' => array('fontWeight' => 700),
                    ),
                ),
                // Single-paragraph node that also declares paragraphSpacing: there
                // is no paragraph boundary, so it must render exactly as before —
                // no per-paragraph wrapper, no margin, no diagnostic.
                array(
                    'id'         => 'psplit:3',
                    'type'       => 'TEXT',
                    'name'       => 'Single paragraph spacing',
                    'characters' => 'Only one paragraph here',
                    'style'      => array(
                        'fontFamily'       => 'Inter',
                        'fontSize'         => 16,
                        'paragraphSpacing' => 18,
                    ),
                ),
            ),
        ),
    ),
));
$paragraphSplitHtml = $fileContent($paragraphSplitResult, 'index.html');
$paragraphSplitDiagnostics = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $paragraphSplitResult['diagnostics'] ?? array()
);

// The bold override span survives, nested inside the first paragraph box, and
// the second paragraph is its own box with no margin.
$assert(str_contains($paragraphSplitHtml, '<span style="display:block;margin-bottom:12px"><span style="font-weight:700">Bold</span> intro</span><span style="display:block">plain rest</span>'), 'paragraph-split-preserves-inline-span-in-correct-paragraph');

// Single-paragraph node: no per-paragraph wrapper and no diagnostic.
$assert(str_contains($paragraphSplitHtml, '>Only one paragraph here</p>'), 'single-paragraph-spacing-no-wrapper');
$assert(! in_array('paragraph_spacing_not_applied', $paragraphSplitDiagnostics, true), 'single-paragraph-spacing-no-diagnostic');

// Kiwi (.fig) inline style overrides (#328).
//
// The REST API fixture above passes `characters` / `characterStyleOverrides` /
// `styleOverrideTable` (an id-keyed map) flat on the node. Real `.fig` (Kiwi) files
// encode the same data differently, and the figma-transformer must decode and
// bridge that shape into the same `segments` contract:
//   - text lives under `textData.characters`
//   - per-character run IDs live under `textData.characterStyleIDs`
//   - the override table is `textData.styleOverrideTable`, a `NodeChange[]` where
//     each entry carries a `styleID` plus the overriding properties, and override
//     text color rides on `fillPaints` (not REST `fills`).
// This fixture mirrors that .fig shape so the bridge is exercised end-to-end:
// decode -> normalize (id-keyed + fillPaints color + fontName→weight) -> emit span.
$kiwiInlineTextStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Inline Text Style Fixture',
    'nodes' => array(
        array(
            'id'       => 'kts:1',
            'type'     => 'FRAME',
            'name'     => 'Kiwi inline text frame',
            'width'    => 1200,
            'height'   => 400,
            'children' => array(
                // "Hello blue " (11 chars) in base black, "world" (5 chars) in blue
                // via a NodeChange-shaped override entry carrying `fillPaints`.
                array(
                    'id'         => 'kts:2',
                    'type'       => 'TEXT',
                    'name'       => 'Kiwi two color text',
                    'fontName'   => array('family' => 'Inter', 'style' => 'Regular'),
                    'fontSize'   => 16,
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                    'textData'   => array(
                        'characters'        => 'Hello blue world',
                        'characterStyleIDs' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1),
                        'styleOverrideTable' => array(
                            array(
                                'styleID'    => 1,
                                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1))),
                            ),
                        ),
                    ),
                ),
                // "Bold" (4 chars) at weight 700 via a NodeChange-shaped override
                // entry carrying a bold `fontName`, " plain text" (11 chars) at base.
                array(
                    'id'         => 'kts:3',
                    'type'       => 'TEXT',
                    'name'       => 'Kiwi mixed weight text',
                    'fontName'   => array('family' => 'Inter', 'style' => 'Regular'),
                    'fontSize'   => 16,
                    'textData'   => array(
                        'characters'        => 'Bold plain text',
                        'characterStyleIDs' => array(1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                        'styleOverrideTable' => array(
                            array(
                                'styleID'  => 1,
                                'fontName' => array('family' => 'Inter', 'style' => 'Bold'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$kiwiInlineTextStyleHtml = $fileContent($kiwiInlineTextStyleResult, 'index.html');

// Kiwi two-color: only the "world" run differs in fill color — it gets a color span,
// and the non-overridden "Hello blue " text is emitted unwrapped.
$assert(str_contains($kiwiInlineTextStyleHtml, 'Hello blue <span style="color:#0000ff">world</span>'), 'kiwi-inline-style-two-color-spans');
// Kiwi mixed-weight: only "Bold" differs in font-weight — derived from the override
// entry's bold `fontName` — and " plain text" stays unwrapped.
$assert(str_contains($kiwiInlineTextStyleHtml, '<span style="font-weight:700">Bold</span> plain text'), 'kiwi-inline-style-mixed-weight-spans');

// Font embedding: a known web font (Inter) resolves to a weight-aware Google Fonts
// @font-face import, while an unknown family (Skolar Latin) stays actionable. This
// mirrors the David Perell .fig matrix where Inter + Skolar Latin rendered in a
// fallback system font because no font CSS was emitted.
$fontEmbeddingResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Font Embedding Fixture',
    'nodes' => array(
        array(
            'id'       => 'fe:1',
            'type'     => 'FRAME',
            'name'     => 'Typography frame',
            'children' => array(
                array('id' => 'fe:2', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Bold heading', 'fontName' => array('family' => 'Inter', 'style' => 'Bold'), 'fontSize' => 48),
                array('id' => 'fe:3', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Regular body copy', 'fontName' => array('family' => 'Inter', 'style' => 'Regular'), 'fontSize' => 18),
                array('id' => 'fe:4', 'type' => 'TEXT', 'name' => 'Serif accent', 'characters' => 'Serif accent', 'fontName' => array('family' => 'Skolar Latin', 'style' => 'Medium'), 'fontSize' => 24, 'style' => array('fontWeight' => 500)),
            ),
        ),
    ),
));
$fontEmbeddingCss = $fileContent($fontEmbeddingResult, 'style.css');
$fontEmbeddingDiagnostics = $fontEmbeddingResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$fontEmbeddingFonts = $fontEmbeddingDiagnostics['fonts'] ?? array();
$fontEmbeddingCodes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $fontEmbeddingResult['diagnostics'] ?? array());
$fontEmbeddingCoverage = array();
foreach ( is_array($fontEmbeddingFonts['coverage'] ?? null) ? $fontEmbeddingFonts['coverage'] : array() as $coverageEntry ) {
    $fontEmbeddingCoverage[(string) ($coverageEntry['family'] ?? '')] = $coverageEntry;
}
$assert(str_contains($fontEmbeddingCss, "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap');"), 'font-embedding-inter-cdn-import');
$assert(str_contains($fontEmbeddingCss, 'font-family:"Inter", sans-serif'), 'font-embedding-inter-fallback-stack');
$assert(str_contains($fontEmbeddingCss, 'font-family:"Skolar Latin", sans-serif'), 'font-embedding-skolar-fallback-stack');
$assert(true === ($fontEmbeddingFonts['materialized'] ?? null), 'font-embedding-materialized');
$assert(false === ($fontEmbeddingFonts['css_supplied'] ?? null), 'font-embedding-not-operator-supplied');
$assert(array('Inter') === ($fontEmbeddingFonts['resolved_css'] ?? null), 'font-embedding-inter-resolved');
$assert(array('Skolar Latin') === ($fontEmbeddingFonts['missing_css'] ?? null), 'font-embedding-skolar-unresolved');
$assert('cdn_google_fonts' === ($fontEmbeddingCoverage['Inter']['resolution'] ?? null) && array(400, 700) === ($fontEmbeddingCoverage['Inter']['weights'] ?? null), 'font-embedding-inter-coverage-weights');
$assert(true === ($fontEmbeddingCoverage['Skolar Latin']['needs_operator_font'] ?? null), 'font-embedding-skolar-needs-operator-font');
$assert(in_array('font_css_missing_for_source_font', $fontEmbeddingCodes, true) && 1 === count(array_filter($fontEmbeddingCodes, static fn (string $code): bool => 'font_css_missing_for_source_font' === $code)), 'font-embedding-single-unresolved-diagnostic');
$fontOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Font Override Fixture',
    'nodes' => array(
        array('id' => 'fo:1', 'type' => 'TEXT', 'name' => 'Override text', 'characters' => 'Override', 'fontName' => array('family' => 'Inter', 'style' => 'Regular'), 'fontSize' => 20),
    ),
), array('font_css' => '@font-face{font-family:"Inter";src:url("assets/inter.woff2") format("woff2")}'));
$fontOverrideCss = $fileContent($fontOverrideResult, 'style.css');
$fontOverrideFonts = $fontOverrideResult['source_reports']['figma']['html']['transform_diagnostics']['fonts'] ?? array();
$assert(str_starts_with($fontOverrideCss, '@font-face{font-family:"Inter";src:url("assets/inter.woff2") format("woff2")}'), 'font-embedding-operator-override-passthrough');
$assert(! str_contains($fontOverrideCss, 'fonts.googleapis.com'), 'font-embedding-operator-override-skips-cdn');
$assert(true === ($fontOverrideFonts['css_supplied'] ?? null) && array() === ($fontOverrideFonts['missing_css'] ?? null), 'font-embedding-operator-override-clears-missing');

// Barlow variant resolver: Barlow Condensed and Barlow Semi Condensed are distinct
// Google Fonts families (not axis parameters) and must emit separate CDN family specs.
$barlowVariantsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Barlow Variants Fixture',
    'nodes' => array(
        array(
            'id'       => 'bv:1',
            'type'     => 'FRAME',
            'name'     => 'Barlow frame',
            'children' => array(
                array('id' => 'bv:2', 'type' => 'TEXT', 'name' => 'Barlow heading', 'characters' => 'Barlow heading', 'fontName' => array('family' => 'Barlow', 'style' => 'Bold'), 'fontSize' => 32),
                array('id' => 'bv:3', 'type' => 'TEXT', 'name' => 'Barlow Condensed text', 'characters' => 'Condensed', 'fontName' => array('family' => 'Barlow Condensed', 'style' => 'Regular'), 'fontSize' => 16),
                array('id' => 'bv:4', 'type' => 'TEXT', 'name' => 'Barlow Semi Condensed text', 'characters' => 'Semi condensed', 'fontName' => array('family' => 'Barlow Semi Condensed', 'style' => 'Medium'), 'fontSize' => 16, 'style' => array('fontWeight' => 500)),
            ),
        ),
    ),
));
$barlowVariantsCss = $fileContent($barlowVariantsResult, 'style.css');
$barlowVariantsFonts = $barlowVariantsResult['source_reports']['figma']['html']['transform_diagnostics']['fonts'] ?? array();
$barlowVariantsCoverage = array();
foreach ( is_array($barlowVariantsFonts['coverage'] ?? null) ? $barlowVariantsFonts['coverage'] : array() as $coverageEntry ) {
    $barlowVariantsCoverage[(string) ($coverageEntry['family'] ?? '')] = $coverageEntry;
}
$assert(str_contains($barlowVariantsCss, 'family=Barlow+Condensed'), 'barlow-condensed-cdn-import');
$assert(str_contains($barlowVariantsCss, 'family=Barlow+Semi+Condensed'), 'barlow-semi-condensed-cdn-import');
$assert('cdn_google_fonts' === ($barlowVariantsCoverage['Barlow']['resolution'] ?? null), 'barlow-resolved-via-cdn');
$assert('cdn_google_fonts' === ($barlowVariantsCoverage['Barlow Condensed']['resolution'] ?? null), 'barlow-condensed-resolved-via-cdn');
$assert('cdn_google_fonts' === ($barlowVariantsCoverage['Barlow Semi Condensed']['resolution'] ?? null), 'barlow-semi-condensed-resolved-via-cdn');
$assert(false === ($barlowVariantsCoverage['Barlow Condensed']['needs_operator_font'] ?? null), 'barlow-condensed-no-operator-font-needed');
$assert(false === ($barlowVariantsCoverage['Barlow Semi Condensed']['needs_operator_font'] ?? null), 'barlow-semi-condensed-no-operator-font-needed');
$assert(array() === ($barlowVariantsFonts['missing_css'] ?? null), 'barlow-variants-all-resolved');

// Syne: a Google Fonts family that resolves via CDN.
// Cabinet Grotesk: a Fontshare-only font not present in the Google Fonts metadata
// endpoint — it appears as unresolved (needs_operator_font: true) when no operator
// font_css is supplied.
$syneResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Syne Cabinet Grotesk Fixture',
    'nodes' => array(
        array(
            'id'       => 'sc:1',
            'type'     => 'FRAME',
            'name'     => 'Design frame',
            'children' => array(
                array('id' => 'sc:2', 'type' => 'TEXT', 'name' => 'Syne text', 'characters' => 'Syne heading', 'fontName' => array('family' => 'Syne', 'style' => 'Bold'), 'fontSize' => 40),
                array('id' => 'sc:3', 'type' => 'TEXT', 'name' => 'Cabinet Grotesk text', 'characters' => 'Cabinet body', 'fontName' => array('family' => 'Cabinet Grotesk', 'style' => 'Regular'), 'fontSize' => 16),
            ),
        ),
    ),
));
$syneFonts = $syneResult['source_reports']['figma']['html']['transform_diagnostics']['fonts'] ?? array();
$syneCoverage = array();
foreach ( is_array($syneFonts['coverage'] ?? null) ? $syneFonts['coverage'] : array() as $coverageEntry ) {
    $syneCoverage[(string) ($coverageEntry['family'] ?? '')] = $coverageEntry;
}
$assert('cdn_google_fonts' === ($syneCoverage['Syne']['resolution'] ?? null), 'syne-resolved-via-cdn');
$assert('unresolved' === ($syneCoverage['Cabinet Grotesk']['resolution'] ?? null), 'cabinet-grotesk-not-on-google-fonts');
$assert(true === ($syneCoverage['Cabinet Grotesk']['needs_operator_font'] ?? null), 'cabinet-grotesk-needs-operator-font');

// System / web-safe fonts resolve via the generated system-fonts table
// (scripts/generate-system-fonts.php, sourced from Modern Font Stacks + the
// classic web-safe set). Helvetica Neue and Segoe UI are OS system faces that
// are NOT on Google Fonts: they must resolve `web_safe` with no @import and no
// operator-font diagnostic, carrying their generated fallback stacks. This
// mirrors the FSE Pilot Build Theme .fig parity diagnostic where Helvetica Neue
// was reported unresolved. Critically, a genuinely-unknown brand/custom face
// ("Acme Brand Sans") that is neither on Google Fonts nor in the system table
// must STILL resolve `unresolved` and raise font_css_missing_for_source_font —
// the generated table widens known-system coverage, it does not silence the
// parity signal for real custom typefaces.
$systemFontResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'System Font Fixture',
    'nodes' => array(
        array(
            'id'       => 'sf:1',
            'type'     => 'FRAME',
            'name'     => 'System font frame',
            'children' => array(
                array('id' => 'sf:2', 'type' => 'TEXT', 'name' => 'Helvetica Neue heading', 'characters' => 'Helvetica Neue heading', 'fontName' => array('family' => 'Helvetica Neue', 'style' => 'Bold'), 'fontSize' => 40),
                array('id' => 'sf:3', 'type' => 'TEXT', 'name' => 'Segoe UI body', 'characters' => 'Segoe UI body copy', 'fontName' => array('family' => 'Segoe UI', 'style' => 'Regular'), 'fontSize' => 16),
                array('id' => 'sf:4', 'type' => 'TEXT', 'name' => 'Brand heading', 'characters' => 'Brand heading', 'fontName' => array('family' => 'Acme Brand Sans', 'style' => 'Regular'), 'fontSize' => 24),
            ),
        ),
    ),
));
$systemFontCss = $fileContent($systemFontResult, 'style.css');
$systemFontFonts = $systemFontResult['source_reports']['figma']['html']['transform_diagnostics']['fonts'] ?? array();
$systemFontCodes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $systemFontResult['diagnostics'] ?? array());
$systemFontCoverage = array();
foreach ( is_array($systemFontFonts['coverage'] ?? null) ? $systemFontFonts['coverage'] : array() as $coverageEntry ) {
    $systemFontCoverage[(string) ($coverageEntry['family'] ?? '')] = $coverageEntry;
}
$assert('web_safe' === ($systemFontCoverage['Helvetica Neue']['resolution'] ?? null), 'helvetica-neue-resolves-web-safe');
$assert(false === ($systemFontCoverage['Helvetica Neue']['needs_operator_font'] ?? null), 'helvetica-neue-no-operator-font-needed');
$assert('"Helvetica Neue", Helvetica, Arial, sans-serif' === ($systemFontCoverage['Helvetica Neue']['fallback_stack'] ?? null), 'helvetica-neue-system-fallback-stack');
$assert('web_safe' === ($systemFontCoverage['Segoe UI']['resolution'] ?? null), 'segoe-ui-resolves-web-safe');
$assert(false === ($systemFontCoverage['Segoe UI']['needs_operator_font'] ?? null), 'segoe-ui-no-operator-font-needed');
$assert('"Segoe UI", Tahoma, Geneva, Verdana, sans-serif' === ($systemFontCoverage['Segoe UI']['fallback_stack'] ?? null), 'segoe-ui-system-fallback-stack');
$assert(! in_array('Helvetica Neue', $systemFontFonts['missing_css'] ?? array(), true) && ! in_array('Segoe UI', $systemFontFonts['missing_css'] ?? array(), true), 'system-fonts-not-in-missing-css');
$assert(! str_contains($systemFontCss, 'Helvetica+Neue') && ! str_contains($systemFontCss, 'Segoe+UI') && ! str_contains($systemFontCss, 'fonts.googleapis.com'), 'system-fonts-emit-no-cdn-import');
// Boundary: a genuinely-unknown custom typeface stays unresolved and keeps the diagnostic.
$assert('unresolved' === ($systemFontCoverage['Acme Brand Sans']['resolution'] ?? null), 'custom-font-stays-unresolved');
$assert(true === ($systemFontCoverage['Acme Brand Sans']['needs_operator_font'] ?? null), 'custom-font-needs-operator-font');
$assert(in_array('Acme Brand Sans', $systemFontFonts['missing_css'] ?? array(), true), 'custom-font-in-missing-css');
$assert(in_array('font_css_missing_for_source_font', $systemFontCodes, true), 'custom-font-raises-missing-diagnostic');

$assetReferenceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Asset Reference Fixture',
    'assets' => array(
        'image-hash-1' => array(
            'id'        => 'image-hash-1',
            'hash'      => 'image-hash-1',
            'name'      => 'Archive Image',
            'mime_type' => 'image/png',
            'content'   => 'png-bytes',
        ),
        'slugged-image' => array(
            'id'        => 'slugged-image',
            'name'      => 'Slugged Image',
            'mime_type' => 'image/png',
            'content'   => 'slugged-png-bytes',
        ),
    ),
    'nodes'  => array(
        array(
            'id'       => '4:1',
            'type'     => 'FRAME',
            'name'     => 'Asset Frame',
            'children' => array(
                array(
                    'id'     => '4:2',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Image Fill',
                    'width'  => 20,
                    'height' => 20,
                    'fills'  => array(
                        array('type' => 'IMAGE', 'imageHash' => 'image-hash-1'),
                    ),
                ),
                array(
                    'id'     => '4:3',
                    'type'   => 'VECTOR',
                    'name'   => 'Icon Vector',
                    'width'  => 10,
                    'height' => 10,
                ),
                array(
                    'id'     => '4:5',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Slugged Image Fill',
                    'width'  => 20,
                    'height' => 20,
                    'fills'  => array(
                        array('type' => 'IMAGE', 'imageHash' => 'Slugged Image!'),
                    ),
                ),
                array(
                    'id'          => '4:4',
                    'type'        => 'VECTOR',
                    'name'        => 'Path Icon',
                    'width'       => 24,
                    'height'      => 24,
                    'fills'       => array(
                        array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1)),
                    ),
                    'vectorPaths' => array(
                        array(
                            'data'        => 'M 1 1 L 23 1 L 12 23 Z',
                            'windingRule' => 'EVENODD',
                        ),
                    ),
                ),
            ),
        ),
    ),
));

$assetReferenceCss = $fileContent($assetReferenceResult, 'style.css');
$assetReferenceHtml = $fileContent($assetReferenceResult, 'index.html');
$assetReferenceReport = $assetReferenceResult['source_reports']['figma']['scenegraph'] ?? array();
$assetReferenceDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $assetReferenceResult['diagnostics'] ?? array()
);

$assert(2 === ($assetReferenceResult['metrics']['asset_reference_count'] ?? null), 'normalized-image-reference-count');
$assert(in_array((string) ($assetReferenceReport['asset_references'][0]['source_key'] ?? null), array('imageHash', 'ref'), true), 'normalized-image-reference-source-key');
$assert('image-hash-1' === ($assetReferenceReport['asset_references'][0]['ref'] ?? null), 'normalized-image-reference-ref');
$assert(str_contains($assetReferenceCss, 'background-image:url("assets/archive-image.png")'), 'normalized-image-reference-css');
$assert(str_contains($assetReferenceCss, 'background-image:url("assets/slugged-image.png")'), 'normalized-image-reference-slug-css');
$assert(str_contains($assetReferenceHtml, 'data-figma-vector="true"'), 'supported-vector-svg-html');
$assert(str_contains($assetReferenceHtml, '<path d="M1 1L23 1 12 23Z" fill="#0000ff" fill-rule="evenodd"/>'), 'supported-vector-path-derived-svg');
$assert(str_contains($assetReferenceHtml, 'data-figma-unsupported-vector="true"'), 'unsupported-vector-placeholder-html');
$assert(! str_contains($assetReferenceHtml, 'Unsupported Figma VECTOR'), 'unsupported-vector-placeholder-text-hidden');
$assert(in_array('unsupported_vector_node_placeholder', $assetReferenceDiagnosticCodes, true), 'unsupported-vector-diagnostic');
$assetReferenceTransformDiagnostics = $assetReferenceResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert(2 === ($assetReferenceTransformDiagnostics['images']['resolved_assets'] ?? null), 'asset-reference-diagnostics-image-resolved');
$assert(2 === ($assetReferenceTransformDiagnostics['vectors']['nodes'] ?? null), 'asset-reference-diagnostics-vector-count');
$assert(1 === ($assetReferenceTransformDiagnostics['vectors']['rendered_paths'] ?? null), 'asset-reference-diagnostics-vector-path-count');
$assert(1 === ($assetReferenceTransformDiagnostics['vectors']['placeholders'] ?? null), 'asset-reference-diagnostics-vector-placeholder-count');
$assert('4:3' === ($assetReferenceTransformDiagnostics['vectors']['placeholder_nodes'][0]['node_id'] ?? null), 'asset-reference-diagnostics-vector-placeholder-node');

$pluginAssetResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Plugin Asset Fixture',
    'assets' => array(
        'plugin-photo-asset' => array(
            'id'        => 'plugin-photo-asset',
            'name'      => 'Plugin Photo',
            'imageHash' => 'plugin-image-hash',
            'dataUrl'   => 'data:image/png;base64,' . base64_encode('plugin-png-bytes'),
        ),
        'plugin-icon-asset'  => array(
            'id'             => 'plugin-icon-asset',
            'name'           => 'Plugin Icon',
            'node_id'        => 'plugin:vector',
            'mime_type'      => 'image/svg+xml',
            'content_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><path d="M0 0h8v8z"/></svg>'),
        ),
    ),
    'nodes'  => array(
        array(
            'id'       => 'plugin:frame',
            'type'     => 'FRAME',
            'name'     => 'Plugin frame',
            'children' => array(
                array(
                    'id'     => 'plugin:image',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Plugin image',
                    'width'  => 40,
                    'height' => 30,
                    'fills'  => array(
                        array('type' => 'IMAGE', 'imageRef' => 'plugin-image-hash'),
                    ),
                ),
                array(
                    'id'     => 'plugin:vector',
                    'type'   => 'VECTOR',
                    'name'   => 'Plugin Icon',
                    'width'  => 8,
                    'height' => 8,
                ),
            ),
        ),
    ),
));
$pluginAssetCss = $fileContent($pluginAssetResult, 'style.css');
$pluginAssetHtml = $fileContent($pluginAssetResult, 'index.html');
$pluginAssetFiles = $pluginAssetResult['files'] ?? array();
$pluginAssetFileContent = static function (array $files, string $path): string {
    foreach ( $files as $file ) {
        if ( is_array($file) && $path === ($file['path'] ?? null) ) {
            return (string) ($file['content'] ?? '');
        }
    }

    return '';
};

$assert(str_contains($pluginAssetCss, '.figma-node-plugin-image-plugin-image{width:40px;height:30px;position:absolute;background-image:url("assets/plugin-photo.png")'), 'plugin-data-url-image-background-css');
$assert('plugin-png-bytes' === $pluginAssetFileContent($pluginAssetFiles, 'assets/plugin-photo.png'), 'plugin-data-url-image-asset-file');
$assert(str_contains($pluginAssetCss, '.figma-node-plugin-vector-plugin-icon{width:8px;height:8px;position:absolute;background-image:url("assets/plugin-icon.svg")'), 'plugin-vector-fallback-background-css');
$assert(! str_contains($pluginAssetHtml, 'Unsupported Figma VECTOR'), 'plugin-vector-fallback-avoids-unsupported-placeholder');
$assert(str_contains($pluginAssetHtml, 'data-figma-node-id="plugin:vector"') && ! str_contains($pluginAssetHtml, 'data-figma-unsupported-vector="true"'), 'plugin-vector-fallback-not-marked-unsupported');
$assert(str_contains($pluginAssetFileContent($pluginAssetFiles, 'assets/plugin-icon.svg'), '<svg xmlns="http://www.w3.org/2000/svg"'), 'plugin-content-base64-vector-asset-file');
$pluginTransformDiagnostics = $pluginAssetResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert('blocks-engine/figma-transformer/transform-diagnostics/v1' === ($pluginTransformDiagnostics['schema'] ?? null), 'plugin-transform-diagnostics-schema');
$assert(2 === ($pluginTransformDiagnostics['images']['paint_refs'] ?? null), 'plugin-transform-diagnostics-image-paint-refs');
$assert(1 === ($pluginTransformDiagnostics['images']['resolved_assets'] ?? null), 'plugin-transform-diagnostics-image-resolved-assets');
$assert(array() === ($pluginTransformDiagnostics['images']['missing_assets'] ?? null), 'plugin-transform-diagnostics-no-missing-image-assets');
$assert(1 === ($pluginTransformDiagnostics['vectors']['nodes'] ?? null), 'plugin-transform-diagnostics-vector-node-count');
$assert(1 === ($pluginTransformDiagnostics['vectors']['rendered_asset_fallbacks'] ?? null), 'plugin-transform-diagnostics-vector-asset-fallback');
$assert(0 === ($pluginTransformDiagnostics['vectors']['placeholders'] ?? null), 'plugin-transform-diagnostics-no-vector-placeholders');
$assert(in_array('assets/plugin-icon.svg', $pluginTransformDiagnostics['assets']['paths'] ?? array(), true), 'plugin-transform-diagnostics-asset-paths-vector');
$assert(0 === ($pluginTransformDiagnostics['layout']['large_negative_left_count'] ?? null), 'plugin-transform-diagnostics-layout-no-large-negative-left');

$layoutFidelityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Layout Fidelity Fixture',
    'nodes' => array(
        array(
            'id'                    => '5:1',
            'type'                  => 'FRAME',
            'name'                  => 'Layout frame',
            'absoluteBoundingBox'   => array('x' => 100, 'y' => 50, 'width' => 500, 'height' => 300),
            'layoutMode'            => 'HORIZONTAL',
            'primaryAxisAlignItems' => 'MIN',
            'counterAxisAlignItems' => 'STRETCH',
            'clipsContent'          => true,
            'children'              => array(
                array(
                    'id'                     => '5:2',
                    'type'                   => 'RECTANGLE',
                    'name'                   => 'Fixed card',
                    'width'                  => 100,
                    'height'                 => 80,
                    'layoutSizingHorizontal' => 'FIXED',
                    'layoutSizingVertical'   => 'FIXED',
                    'opacity'                => 0.6,
                    'rotation'               => 15,
                ),
                array(
                    'id'                     => '5:3',
                    'type'                   => 'TEXT',
                    'name'                   => 'Hug label',
                    'characters'             => 'Source text',
                    'fontSize'               => 12,
                    'layoutSizingHorizontal' => 'HUG',
                    'layoutSizingVertical'   => 'HUG',
                ),
                array(
                    'id'                     => '5:4',
                    'type'                   => 'RECTANGLE',
                    'name'                   => 'Fill panel',
                    'width'                  => 200,
                    'height'                 => 100,
                    'layoutSizingHorizontal' => 'FILL',
                    'layoutSizingVertical'   => 'FILL',
                    'layoutGrow'             => 1,
                    'layoutAlign'            => 'STRETCH',
                ),
                array(
                    'id'                     => '5:7',
                    'type'                   => 'FRAME',
                    'name'                   => 'Hug flex button',
                    'width'                  => 160,
                    'height'                 => 40,
                    'layoutMode'             => 'HORIZONTAL',
                    'primaryAxisAlignItems'  => 'MAX',
                    'counterAxisAlignItems'  => 'CENTER',
                    'layoutSizingHorizontal' => 'HUG',
                    'layoutSizingVertical'   => 'HUG',
                    'itemSpacing'            => 8,
                    'children'               => array(
                        array('id' => '5:8', 'type' => 'RECTANGLE', 'name' => 'Button icon', 'width' => 16, 'height' => 16),
                        array('id' => '5:9', 'type' => 'TEXT', 'name' => 'Button label', 'characters' => 'Buy now', 'fontSize' => 14),
                    ),
                ),
                array(
                    'id'                  => '5:5',
                    'type'                => 'RECTANGLE',
                    'name'                => 'Absolute badge',
                    'absoluteBoundingBox' => array('x' => 120, 'y' => 70, 'width' => 50, 'height' => 20),
                    'layoutPositioning'   => 'ABSOLUTE',
                    'constraints'         => array('horizontal' => 'LEFT_RIGHT', 'vertical' => 'TOP_BOTTOM'),
                    'fill'                => array('r' => 0, 'g' => 0, 'b' => 0),
                ),
                array(
                    'id'                => '5:6',
                    'type'              => 'RECTANGLE',
                    'name'              => 'Matrix transform',
                    'width'             => 30,
                    'height'            => 30,
                    'relativeTransform' => array(
                        array(0, -1, 40),
                        array(1, 0, 60),
                    ),
                ),
            ),
        ),
    ),
));

$layoutFidelityCss = $fileContent($layoutFidelityResult, 'style.css');
$layoutFidelityFrameVisual = $findVisualNode($layoutFidelityResult, '5:1');
$layoutFidelityFillVisual = $findVisualNode($layoutFidelityResult, '5:4');
$layoutFidelityAbsoluteVisual = $findVisualNode($layoutFidelityResult, '5:5');

$assert(str_contains($layoutFidelityCss, '.figma-node-5-1-layout-frame{width:500px;height:300px;overflow:hidden;position:relative;display:flex;flex-direction:row;justify-content:flex-start;align-items:stretch}'), 'layout-frame-clips-and-positions-absolute-children');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-2-fixed-card{width:100px;height:80px;opacity:0.6;transform:rotate(15deg);flex-shrink:0}'), 'layout-fixed-sizing-and-rotation');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-3-hug-label{width:fit-content;height:fit-content;font-size:12px;flex-shrink:0}'), 'layout-hug-sizing');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-4-fill-panel{width:100%;height:100%;flex-grow:1;flex-shrink:1;align-self:stretch}'), 'layout-fill-sizing-without-source-order');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-7-hug-flex-button{width:160px;height:40px;display:flex;flex-direction:row;justify-content:flex-end;align-items:center;gap:8px;flex-shrink:0}'), 'layout-hug-flex-container-preserves-measured-box');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-5-absolute-badge{width:50px;height:20px;position:absolute;left:20px;right:430px;top:20px;bottom:260px;background:#000000;flex-shrink:0}'), 'layout-absolute-constraints-without-source-z-index');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-6-matrix-transform{width:30px;height:30px;transform:matrix(0,1,-1,0,40,60);transform-origin:0 0;flex-shrink:0}'), 'layout-relative-transform-matrix');
$assert(! str_contains($layoutFidelityCss, 'font-family:Inter') && ! str_contains($layoutFidelityCss, 'body{margin:0;background') && ! str_contains($layoutFidelityCss, 'body{margin:0;color'), 'layout-css-avoids-theme-defaults');
$assert('flex' === ($layoutFidelityFrameVisual['layout']['display'] ?? null) && 'row' === ($layoutFidelityFrameVisual['layout']['flex_direction'] ?? null), 'layout-classifier-visual-map-preserves-flow-container-intent');
$assert('absolute' === ($layoutFidelityAbsoluteVisual['layout']['positioning'] ?? null) && array('x' => 20.0, 'y' => 20.0, 'width' => 50.0, 'height' => 20.0) === ($layoutFidelityAbsoluteVisual['rect'] ?? null), 'layout-classifier-visual-map-aligns-absolute-child-with-css');
$assert(null === ($layoutFidelityFillVisual['layout']['positioning'] ?? null) && 200.0 === ($layoutFidelityFillVisual['rect']['width'] ?? null) && 100.0 === ($layoutFidelityFillVisual['rect']['height'] ?? null), 'layout-classifier-visual-map-keeps-fill-child-in-flex-flow');

$hugOverflowResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Hug Flex Overflow Fixture',
    'nodes' => array(
        array(
            'id'                     => 'hug-overflow:button',
            'type'                   => 'FRAME',
            'name'                   => 'Hug overflow button',
            'width'                  => 73,
            'height'                 => 40,
            'layoutMode'             => 'HORIZONTAL',
            'primaryAxisAlignItems'  => 'MAX',
            'counterAxisAlignItems'  => 'CENTER',
            'layoutSizingHorizontal' => 'HUG',
            'layoutSizingVertical'   => 'HUG',
            'itemSpacing'            => 8,
            'paddingLeft'            => 6,
            'paddingRight'           => 6,
            'children'               => array(
                array('id' => 'hug-overflow:left', 'type' => 'RECTANGLE', 'name' => 'Left intrinsic item', 'width' => 50, 'height' => 20),
                array('id' => 'hug-overflow:right', 'type' => 'RECTANGLE', 'name' => 'Right intrinsic item', 'width' => 50, 'height' => 20),
            ),
        ),
    ),
));
$hugOverflowCss = $fileContent($hugOverflowResult, 'style.css');
$assert(str_contains($hugOverflowCss, '.figma-node-hug-overflow-button-hug-overflow-button{width:max-content;height:40px;display:flex;flex-direction:row;justify-content:flex-end;align-items:center;padding-right:6px;padding-left:6px;gap:8px}'), 'layout-hug-flex-main-axis-expands-to-intrinsic-span');

blocks_engine_figma_transformer_run_visual_node_map_contract($assert);
blocks_engine_figma_transformer_run_diagnostics_evidence_contract($assert);

$kiwiStackLayoutResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Stack Layout Fixture',
    'nodes' => array(
        array(
            'id'                     => 'stack:frame',
            'type'                   => 'FRAME',
            'name'                   => 'Kiwi stack frame',
            'width'                  => 300,
            'height'                 => 200,
            'stackMode'              => 'VERTICAL',
            'stackSpacing'           => 24,
            'stackPadding'           => 8,
            'stackPaddingRight'      => 20,
            'stackPaddingBottom'     => 30,
            'stackPrimaryAlignItems' => 'CENTER',
            'stackCounterAlignItems' => 'MIN',
            'stackWrap'              => 'WRAP',
            'isClip'                 => true,
            'children'               => array(
                array(
                    'id'     => 'stack:child-a',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Child A',
                    'width'  => 50,
                    'height' => 40,
                ),
                array(
                    'id'     => 'stack:child-b',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Child B',
                    'width'  => 60,
                    'height' => 50,
                ),
            ),
        ),
    ),
));
$kiwiStackLayoutCss = $fileContent($kiwiStackLayoutResult, 'style.css');
$assert(str_contains($kiwiStackLayoutCss, '.figma-node-stack-frame-kiwi-stack-frame{width:300px;height:200px;overflow:hidden;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;flex-wrap:wrap;align-content:flex-start;padding-top:8px;padding-right:20px;padding-bottom:30px;padding-left:8px;gap:24px}'), 'kiwi-stack-layout-emits-flex-padding-gap');
$assert(str_contains($kiwiStackLayoutCss, '.figma-node-stack-child-a-child-a{width:50px;height:40px;flex-shrink:0}'), 'kiwi-stack-child-not-absolute');

// .fig (Kiwi) input carries layout intent under flat Kiwi field names the
// normalizer historically ignored in favor of the REST vocabulary, so the
// constraints, stack sizing, child grow, min/max, and absolute-positioning
// signals were pure data loss. This fixture feeds the Kiwi field names with
// their real decoded enum values (ConstraintType MIN/MAX/STRETCH/CENTER,
// StackSize RESIZE_TO_FIT/FIXED, OptionalVector min/maxSize) and proves they
// reach CSS: constraints become pins, stack sizing becomes flex sizing, grow
// becomes flex-grow, and min/maxSize become min/max width/height.
$kiwiLayoutFieldsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Layout Fields Fixture',
    'nodes' => array(
        array(
            'id'                  => 'kiwi-layout:frame',
            'type'                => 'FRAME',
            'name'                => 'Kiwi layout frame',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300),
            'children'            => array(
                array(
                    'id'                   => 'kiwi-layout:stretch-badge',
                    'type'                 => 'RECTANGLE',
                    'name'                 => 'Stretch badge',
                    'absoluteBoundingBox'  => array('x' => 20, 'y' => 30, 'width' => 50, 'height' => 20),
                    'stackPositioning'     => 'ABSOLUTE',
                    'horizontalConstraint' => 'STRETCH',
                    'verticalConstraint'   => 'STRETCH',
                    'fill'                 => array('r' => 0, 'g' => 0, 'b' => 0),
                ),
                array(
                    'id'                   => 'kiwi-layout:far-badge',
                    'type'                 => 'RECTANGLE',
                    'name'                 => 'Far badge',
                    'absoluteBoundingBox'  => array('x' => 300, 'y' => 10, 'width' => 60, 'height' => 40),
                    'stackPositioning'     => 'ABSOLUTE',
                    'horizontalConstraint' => 'MAX',
                    'verticalConstraint'   => 'MIN',
                ),
                array(
                    'id'                   => 'kiwi-layout:center-badge',
                    'type'                 => 'RECTANGLE',
                    'name'                 => 'Center badge',
                    'absoluteBoundingBox'  => array('x' => 175, 'y' => 140, 'width' => 50, 'height' => 20),
                    'stackPositioning'     => 'ABSOLUTE',
                    'horizontalConstraint' => 'CENTER',
                    'verticalConstraint'   => 'CENTER',
                ),
            ),
        ),
        array(
            'id'        => 'kiwi-layout:flexframe',
            'type'      => 'FRAME',
            'name'      => 'Kiwi flex frame',
            'width'     => 300,
            'height'    => 100,
            'stackMode' => 'HORIZONTAL',
            'children'  => array(
                array(
                    'id'                    => 'kiwi-layout:fill-item',
                    'type'                  => 'RECTANGLE',
                    'name'                  => 'Fill item',
                    'width'                 => 80,
                    'height'                => 40,
                    'stackChildPrimaryGrow' => 1,
                    'minSize'               => array('x' => 100, 'y' => 20),
                    'maxSize'               => array('x' => 200, 'y' => 60),
                ),
                array(
                    'id'                 => 'kiwi-layout:hug-frame',
                    'type'               => 'FRAME',
                    'name'               => 'Hug frame',
                    'width'              => 50,
                    'height'             => 40,
                    'stackMode'          => 'HORIZONTAL',
                    'stackPrimarySizing' => 'RESIZE_TO_FIT',
                    'stackCounterSizing' => 'FIXED',
                    'children'           => array(
                        array('id' => 'kiwi-layout:hug-a', 'type' => 'RECTANGLE', 'name' => 'Hug A', 'width' => 30, 'height' => 20),
                        array('id' => 'kiwi-layout:hug-b', 'type' => 'RECTANGLE', 'name' => 'Hug B', 'width' => 40, 'height' => 20),
                    ),
                ),
            ),
        ),
    ),
));
$kiwiLayoutFieldsCss = $fileContent($kiwiLayoutFieldsResult, 'style.css');
// Kiwi STRETCH constraint == REST LEFT_RIGHT/TOP_BOTTOM both-side pin.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-stretch-badge-stretch-badge{width:50px;height:20px;position:absolute;left:20px;right:330px;top:30px;bottom:250px;background:#000000}'), 'kiwi-constraint-stretch-pins-both-edges');
// Kiwi MAX (horizontal) == far-edge pin only; Kiwi MIN (vertical) == near pin.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-far-badge-far-badge{width:60px;height:40px;position:absolute;right:40px;top:10px}'), 'kiwi-constraint-max-pins-far-edge-only');
// Kiwi CENTER == fixed offset from parent center via calc(), no transform.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-center-badge-center-badge{width:50px;height:20px;position:absolute;left:calc(50% - 25px);top:calc(50% - 10px)}'), 'kiwi-constraint-center-uses-calc-offset');
// stackChildPrimaryGrow -> flex-grow; minSize/maxSize -> min/max width/height.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-fill-item-fill-item{width:80px;height:40px;min-width:100px;max-width:200px;min-height:20px;max-height:60px;flex-grow:1}'), 'kiwi-grow-and-min-max-size');
// stackPrimarySizing RESIZE_TO_FIT -> HUG main axis; stackCounterSizing FIXED -> fixed cross axis.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-hug-frame-hug-frame{width:max-content;height:40px;display:flex;flex-direction:row;flex-shrink:0}'), 'kiwi-stack-sizing-bridges-to-flex-sizing');

$plainFrameLayoutResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Plain Frame Layout Fixture',
    'nodes' => array(
        array(
            'id'                  => 'plain:frame',
            'type'                => 'FRAME',
            'name'                => 'Plain layout frame',
            'absoluteBoundingBox' => array('x' => 100, 'y' => 50, 'width' => 400, 'height' => 300),
            'children'            => array(
                array(
                    'id'                  => 'plain:first',
                    'type'                => 'RECTANGLE',
                    'name'                => 'First positioned layer',
                    'absoluteBoundingBox' => array('x' => 120, 'y' => 70, 'width' => 90, 'height' => 40),
                ),
                array(
                    'id'                  => 'plain:second',
                    'type'                => 'TEXT',
                    'name'                => 'Second positioned text',
                    'characters'          => 'Positioned',
                    'absoluteBoundingBox' => array('x' => 300, 'y' => 200, 'width' => 120, 'height' => 32),
                ),
            ),
        ),
    ),
));
$plainFrameLayoutCss = $fileContent($plainFrameLayoutResult, 'style.css');
$assert(str_contains($plainFrameLayoutCss, '.figma-node-plain-frame-plain-layout-frame{width:400px;height:300px;position:relative}'), 'plain-frame-becomes-freeform-positioned-canvas');
$assert(str_contains($plainFrameLayoutCss, '.figma-node-plain-first-first-positioned-layer{width:90px;height:40px;position:absolute;left:20px;top:20px'), 'plain-frame-first-child-positioned-relative-to-parent');
$assert(str_contains($plainFrameLayoutCss, '.figma-node-plain-second-second-positioned-text{width:120px;height:32px;position:absolute;left:200px;top:150px'), 'plain-frame-text-child-positioned-relative-to-parent');

$singleChildOffsetResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Single Child Offset Fixture',
    'nodes' => array(
        array(
            'id'                  => 'single-offset:frame',
            'type'                => 'FRAME',
            'name'                => 'Single offset frame',
            'absoluteBoundingBox' => array('x' => 100, 'y' => 50, 'width' => 400, 'height' => 300),
            'children'            => array(
                array(
                    'id'                  => 'single-offset:child',
                    'type'                => 'RECTANGLE',
                    'name'                => 'Offset child',
                    'absoluteBoundingBox' => array('x' => 160, 'y' => 125, 'width' => 90, 'height' => 40),
                ),
            ),
        ),
    ),
));
$singleChildOffsetCss = $fileContent($singleChildOffsetResult, 'style.css');
$singleChildOffsetVisualNode = $findVisualNode($singleChildOffsetResult, 'single-offset:child');
$assert(str_contains($singleChildOffsetCss, '.figma-node-single-offset-frame-single-offset-frame{width:400px;height:300px;position:relative}'), 'single-child-offset-frame-becomes-freeform');
$assert(str_contains($singleChildOffsetCss, '.figma-node-single-offset-child-offset-child{width:90px;height:40px;position:absolute;left:60px;top:75px'), 'single-child-offset-child-positioned-relative-to-parent');
$assert(array('x' => 60.0, 'y' => 75.0, 'width' => 90.0, 'height' => 40.0) === ($singleChildOffsetVisualNode['rect'] ?? null), 'single-child-offset-visual-node-keeps-source-offset');

$nestedMissingOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nested Missing Origin Fixture',
    'nodes' => array(
        array(
            'id'       => 'missing-origin:button-shell',
            'type'     => 'GROUP',
            'name'     => 'Button shell',
            'width'    => 220,
            'height'   => 64,
            'children' => array(
                array(
                    'id'     => 'missing-origin:label',
                    'type'   => 'TEXT',
                    'name'   => 'Button label',
                    'text'   => 'Read more',
                    'x'      => 34,
                    'y'      => 21,
                    'width'  => 96,
                    'height' => 22,
                ),
                array(
                    'id'                 => 'missing-origin:icon',
                    'type'               => 'VECTOR',
                    'name'               => 'Button icon',
                    'x'                  => 34,
                    'y'                  => 8,
                    'width'              => 16,
                    'height'             => 16,
                    'figma_vector_paths' => array(array('data' => 'M0 0L16 8L0 16Z')),
                ),
                array(
                    'id'           => 'missing-origin:rounded',
                    'type'         => 'RECTANGLE',
                    'name'         => 'Decorative rounded plate',
                    'x'            => 0,
                    'y'            => -80,
                    'width'        => 220,
                    'height'       => 64,
                    'cornerRadius' => 18,
                ),
            ),
        ),
    ),
));
$nestedMissingOriginCss = $fileContent($nestedMissingOriginResult, 'style.css');
$nestedMissingOriginLabel = $findVisualNode($nestedMissingOriginResult, 'missing-origin:label');
$nestedMissingOriginIcon = $findVisualNode($nestedMissingOriginResult, 'missing-origin:icon');
$nestedMissingOriginRounded = $findVisualNode($nestedMissingOriginResult, 'missing-origin:rounded');
$assert(str_contains($nestedMissingOriginCss, '.figma-node-missing-origin-button-shell-button-shell{width:220px;height:64px;position:relative}'), 'nested-missing-origin-parent-becomes-freeform');
$assert(str_contains($nestedMissingOriginCss, '.figma-node-missing-origin-label-button-label{width:96px;height:22px;position:absolute;left:34px;top:21px'), 'nested-missing-origin-text-keeps-authored-x');
$assert(str_contains($nestedMissingOriginCss, '.figma-node-missing-origin-icon-button-icon{width:16px;height:16px;position:absolute;left:34px;top:8px'), 'nested-missing-origin-icon-keeps-authored-x');
$assert(str_contains($nestedMissingOriginCss, '.figma-node-missing-origin-rounded-decorative-rounded-plate{width:220px;height:64px;position:absolute;left:0px;top:-80px'), 'nested-missing-origin-rounded-keeps-negative-y');
$assert(array('x' => 34.0, 'y' => 21.0, 'width' => 96.0, 'height' => 22.0) === ($nestedMissingOriginLabel['rect'] ?? null), 'nested-missing-origin-text-visual-map-keeps-authored-x');
$assert(array('x' => 34.0, 'y' => 8.0, 'width' => 16.0, 'height' => 16.0) === ($nestedMissingOriginIcon['rect'] ?? null), 'nested-missing-origin-icon-visual-map-keeps-authored-x');
$assert(array('x' => 0.0, 'y' => -80.0, 'width' => 220.0, 'height' => 64.0) === ($nestedMissingOriginRounded['rect'] ?? null), 'nested-missing-origin-rounded-visual-map-keeps-negative-y');

$selectedFrameOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Selected Frame Origin Fixture',
    'nodes' => array(
        array(
            'id'       => 'origin:frame',
            'type'     => 'FRAME',
            'name'     => 'Selected frame',
            'width'    => 1200,
            'height'   => 800,
            'children' => array(
                array(
                    'id'                  => 'origin:hero',
                    'type'                => 'FRAME',
                    'name'                => 'Hero',
                    'absoluteBoundingBox' => array('x' => -1333, 'y' => -184, 'width' => 1200, 'height' => 600),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
                array(
                    'id'                  => 'origin:cta',
                    'type'                => 'TEXT',
                    'name'                => 'CTA',
                    'characters'          => 'Visible copy',
                    'absoluteBoundingBox' => array('x' => -1133, 'y' => 216, 'width' => 240, 'height' => 40),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
));
$selectedFrameOriginCss = $fileContent($selectedFrameOriginResult, 'style.css');
$assert(str_contains($selectedFrameOriginCss, '.figma-node-origin-hero-hero{width:1200px;height:600px;position:absolute;left:0px;top:0px'), 'selected-frame-origin-normalizes-first-child');
$assert(str_contains($selectedFrameOriginCss, '.figma-node-origin-cta-cta{width:240px;height:40px;position:absolute;left:200px;top:400px'), 'selected-frame-origin-normalizes-text-child');

$zeroOriginSelectedFrameResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Zero Origin Selected Frame Fixture',
    'nodes' => array(
        array(
            'id'                  => 'zero:frame',
            'type'                => 'FRAME',
            'name'                => 'Zero origin selected frame',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 1200, 'height' => 800),
            'children'            => array(
                array(
                    'id'                  => 'zero:hero',
                    'type'                => 'FRAME',
                    'name'                => 'Hero',
                    'absoluteBoundingBox' => array('x' => -1333, 'y' => -184, 'width' => 1200, 'height' => 600),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
                array(
                    'id'                  => 'zero:cta',
                    'type'                => 'TEXT',
                    'name'                => 'CTA',
                    'characters'          => 'Visible copy',
                    'absoluteBoundingBox' => array('x' => -1133, 'y' => 216, 'width' => 240, 'height' => 40),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
));
$zeroOriginSelectedFrameCss = $fileContent($zeroOriginSelectedFrameResult, 'style.css');
$assert(str_contains($zeroOriginSelectedFrameCss, '.figma-node-zero-hero-hero{width:1200px;height:600px;position:absolute;left:0px;top:0px'), 'zero-origin-selected-frame-normalizes-first-child');
$assert(str_contains($zeroOriginSelectedFrameCss, '.figma-node-zero-cta-cta{width:240px;height:40px;position:absolute;left:200px;top:400px'), 'zero-origin-selected-frame-normalizes-text-child');

$nonzeroRootCanvasOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nonzero Root Canvas Origin Fixture',
    'nodes' => array(
        array(
            'id'                  => 'canvas-origin:frame',
            'type'                => 'FRAME',
            'name'                => 'Canvas origin selected frame',
            'absoluteBoundingBox' => array('x' => 4000, 'y' => 0, 'width' => 1200, 'height' => 800),
            'children'            => array(
                array(
                    'id'                  => 'canvas-origin:hero',
                    'type'                => 'FRAME',
                    'name'                => 'Hero',
                    'absoluteBoundingBox' => array('x' => -1333, 'y' => 0, 'width' => 1200, 'height' => 600),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
                array(
                    'id'                  => 'canvas-origin:cta',
                    'type'                => 'TEXT',
                    'name'                => 'CTA',
                    'characters'          => 'Visible copy',
                    'absoluteBoundingBox' => array('x' => -1133, 'y' => 400, 'width' => 240, 'height' => 40),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
));
$nonzeroRootCanvasOriginCss = $fileContent($nonzeroRootCanvasOriginResult, 'style.css');
$assert(str_contains($nonzeroRootCanvasOriginCss, '.figma-node-canvas-origin-hero-hero{width:1200px;height:600px;position:absolute;left:0px;top:0px'), 'nonzero-root-canvas-origin-normalizes-first-child');
$assert(str_contains($nonzeroRootCanvasOriginCss, '.figma-node-canvas-origin-cta-cta{width:240px;height:40px;position:absolute;left:200px;top:400px'), 'nonzero-root-canvas-origin-normalizes-text-child');
$nonzeroRootCanvasOriginDiagnostics = $nonzeroRootCanvasOriginResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert(0 === ($nonzeroRootCanvasOriginDiagnostics['layout']['large_negative_left_count'] ?? null), 'nonzero-root-canvas-origin-diagnostics-no-large-negative-left');

$positiveRootCanvasOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Positive Root Canvas Origin Fixture',
    'nodes' => array(
        array(
            'id'                  => 'positive-origin:frame',
            'type'                => 'FRAME',
            'name'                => 'Positive canvas origin selected frame',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 1200, 'height' => 800),
            'children'            => array(
                array(
                    'id'                  => 'positive-origin:hero',
                    'type'                => 'FRAME',
                    'name'                => 'Hero',
                    'absoluteBoundingBox' => array('x' => 3497, 'y' => 212, 'width' => 1200, 'height' => 600),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
                array(
                    'id'                  => 'positive-origin:cta',
                    'type'                => 'TEXT',
                    'name'                => 'CTA',
                    'characters'          => 'Visible copy',
                    'absoluteBoundingBox' => array('x' => 3697, 'y' => 612, 'width' => 240, 'height' => 40),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
));
$positiveRootCanvasOriginCss = $fileContent($positiveRootCanvasOriginResult, 'style.css');
$assert(str_contains($positiveRootCanvasOriginCss, '.figma-node-positive-origin-hero-hero{width:1200px;height:600px;position:absolute;left:0px;top:0px'), 'positive-root-canvas-origin-normalizes-first-child');
$assert(str_contains($positiveRootCanvasOriginCss, '.figma-node-positive-origin-cta-cta{width:240px;height:40px;position:absolute;left:200px;top:400px'), 'positive-root-canvas-origin-normalizes-text-child');
$positiveRootCanvasOriginDiagnostics = $positiveRootCanvasOriginResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert(0 === ($positiveRootCanvasOriginDiagnostics['layout']['large_absolute_offset_count'] ?? null), 'positive-root-canvas-origin-diagnostics-no-large-offset');

$selectedFrameRebaseNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$selectedFrameRebaseNormalized = $selectedFrameRebaseNormalizer->normalize(array(
    'name'  => 'Selected Frame Explicit Rebase Fixture',
    'nodes' => array(
        array(
            'id'                  => 'selected-rebase:frame',
            'type'                => 'FRAME',
            'name'                => 'Selected Rebase Frame',
            'absoluteBoundingBox' => array('x' => 12000, 'y' => 500, 'width' => 1440, 'height' => 900),
            'children'            => array(
                array(
                    'id'                  => 'selected-rebase:child',
                    'type'                => 'RECTANGLE',
                    'name'                => 'Child',
                    'absoluteBoundingBox' => array('x' => 12120, 'y' => 680, 'width' => 160, 'height' => 60),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
), array('frame_id' => 'selected-rebase:frame'));
$selectedFrameRebaseRoot = $selectedFrameRebaseNormalized['nodes'][0] ?? array();
$selectedFrameRebaseChild = $selectedFrameRebaseRoot['children'][0] ?? array();
$assert(0.0 === ($selectedFrameRebaseRoot['box']['x'] ?? null) && 0.0 === ($selectedFrameRebaseRoot['box']['y'] ?? null), 'selected-frame-rebase-root-origin-zero');
$assert('local' === ($selectedFrameRebaseRoot['box']['coordinate_space'] ?? null) && 'page' === ($selectedFrameRebaseRoot['box']['local_origin'] ?? null), 'selected-frame-rebase-root-marked-page-local');
$assert(120.0 === ($selectedFrameRebaseChild['box']['x'] ?? null) && 180.0 === ($selectedFrameRebaseChild['box']['y'] ?? null), 'selected-frame-rebase-child-subtracts-page-origin');
$assert('local' === ($selectedFrameRebaseChild['box']['coordinate_space'] ?? null) && 'page' === ($selectedFrameRebaseChild['box']['local_origin'] ?? null), 'selected-frame-rebase-child-marked-page-local');
blocks_engine_figma_transformer_run_origin_inference_contract($assert);

$resolvedInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Component Instance Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:button',
            'type'     => 'COMPONENT',
            'name'     => 'Button component',
            'key'      => 'button-key',
            'children' => array(
                array(
                    'id'         => 'component:button-label',
                    'type'       => 'TEXT',
                    'name'       => 'Button label',
                    'characters' => 'Default label',
                ),
            ),
        ),
        array(
            'id'          => 'instance:button',
            'type'        => 'INSTANCE',
            'name'        => 'Primary CTA',
            'componentId' => 'button-key',
            'overrides'   => array(
                array(
                    'nodeId'     => 'component:button-label',
                    'characters' => 'Buy now',
                ),
            ),
        ),
    ),
));

$resolvedInstanceHtml = $fileContent($resolvedInstanceResult, 'index.html');
$resolvedInstanceReport = $resolvedInstanceResult['source_reports']['figma']['scenegraph'] ?? array();

$assert(1 === ($resolvedInstanceReport['component_definition_count'] ?? null), 'component-definition-count');
$assert(1 === ($resolvedInstanceReport['instance_node_count'] ?? null), 'resolved-instance-counts-instance');
$assert(1 === ($resolvedInstanceReport['resolved_instance_count'] ?? null), 'resolved-instance-counts-resolved');
$assert(array() === ($resolvedInstanceReport['unresolved_component_references'] ?? null), 'resolved-instance-no-unresolved');
$assert(str_contains($resolvedInstanceHtml, 'data-figma-node-id="instance:button"'), 'resolved-instance-preserves-instance-id');
$assert(str_contains($resolvedInstanceHtml, 'Buy now'), 'resolved-instance-applies-text-override');

$guidOverrideInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'GUID Override Instance Fixture',
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 47, 'localID' => 25),
            'type'     => 'COMPONENT',
            'name'     => 'Menu item component',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 180, 'localID' => 6416),
                    'type'       => 'TEXT',
                    'name'       => 'Menu label',
                    'characters' => 'Default menu label',
                ),
            ),
        ),
        array(
            'id'         => 'instance:menu-item',
            'type'       => 'INSTANCE',
            'name'       => 'NewMenuItem',
            'symbolData' => array(
                'symbolID' => array('sessionID' => 47, 'localID' => 25),
                'symbolOverrides' => array(
                    array(
                        'guidPath' => array('guids' => array(array('sessionID' => 180, 'localID' => 6416))),
                        'textData' => array('characters' => 'Learn'),
                    ),
                ),
            ),
        ),
    ),
));
$guidOverrideInstanceHtml = $fileContent($guidOverrideInstanceResult, 'index.html');
$assert(str_contains($guidOverrideInstanceHtml, 'Learn'), 'guid-override-instance-applies-text');
$assert(str_contains($guidOverrideInstanceHtml, 'data-figma-node-id="instance:menu-item/180:6416"') && str_contains($guidOverrideInstanceHtml, '>Learn<'), 'guid-override-instance-replaces-default-text');

// Component-property (componentPropAssignments) text overrides (#329 / FSE Pilot).
//
// Figma binds per-instance text content through component properties rather than
// descendant node changes: each master text node carries componentPropRefs
// (componentPropNodeField: TEXT_DATA -> a property definition guid) and each instance
// carries componentPropAssignments (defID -> value.textValue.characters). Resolution
// must render each instance's assigned characters instead of the component master's
// placeholder default. This is the FSE Pilot "Post Title" repeated-placeholder bug:
// a Query Loop of Preview instances whose real post titles were dropped.
$componentPropTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Component Property Text Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:post-card',
            'type'     => 'COMPONENT',
            'name'     => 'Post card component',
            'key'      => 'post-card-key',
            'children' => array(
                array(
                    'id'         => 'component:post-heading',
                    'type'       => 'TEXT',
                    'name'       => 'Heading',
                    'characters' => 'Post Title',
                    // Heading characters are bound to component property 4166:1.
                    'componentPropRefs' => array(
                        array(
                            'defID'                  => array('sessionID' => 4166, 'localID' => 1),
                            'componentPropNodeField' => 'TEXT_DATA',
                        ),
                    ),
                ),
                array(
                    'id'         => 'component:post-excerpt',
                    'type'       => 'TEXT',
                    'name'       => 'Excerpt',
                    'characters' => 'Placeholder excerpt copy.',
                    'componentPropRefs' => array(
                        array(
                            'defID'                  => array('sessionID' => 4166, 'localID' => 2),
                            'componentPropNodeField' => 'TEXT_DATA',
                        ),
                    ),
                ),
            ),
        ),
        // Instance A assigns its own real heading + excerpt.
        array(
            'id'          => 'instance:card-a',
            'type'        => 'INSTANCE',
            'name'        => 'Preview A',
            'componentId' => 'post-card-key',
            'componentPropAssignments' => array(
                array(
                    'defID' => array('sessionID' => 4166, 'localID' => 1),
                    'value' => array('textValue' => array('characters' => 'Welcome to LEGO City')),
                ),
                array(
                    'defID' => array('sessionID' => 4166, 'localID' => 2),
                    'value' => array('textValue' => array('characters' => 'Bricks for everyone.')),
                ),
            ),
        ),
        // Instance B assigns a different real heading (varValue/textDataValue shape).
        array(
            'id'          => 'instance:card-b',
            'type'        => 'INSTANCE',
            'name'        => 'Preview B',
            'componentId' => 'post-card-key',
            'componentPropAssignments' => array(
                array(
                    'defID'    => array('sessionID' => 4166, 'localID' => 1),
                    'varValue' => array('value' => array('textDataValue' => array('characters' => 'Spaceship Set Review'))),
                ),
            ),
        ),
        // Instance C has no assignment: it must keep the component master default.
        array(
            'id'          => 'instance:card-c',
            'type'        => 'INSTANCE',
            'name'        => 'Preview C',
            'componentId' => 'post-card-key',
        ),
    ),
));
$componentPropTextHtml = $fileContent($componentPropTextResult, 'index.html');
$assert(str_contains($componentPropTextHtml, '>Welcome to LEGO City<'), 'component-prop-text-instance-a-heading');
$assert(str_contains($componentPropTextHtml, '>Bricks for everyone.<'), 'component-prop-text-instance-a-excerpt');
$assert(str_contains($componentPropTextHtml, '>Spaceship Set Review<'), 'component-prop-text-instance-b-heading');
// Each instance renders ITS OWN heading, not a shared master clone.
$assert(substr_count($componentPropTextHtml, 'Welcome to LEGO City') === 1 && substr_count($componentPropTextHtml, 'Spaceship Set Review') === 1, 'component-prop-text-distinct-per-instance');
// The placeholder default survives only on the component master definition (rendered
// top-level in this flat fixture) and on instance C which carries no assignment.
// Instances A and B, which DO assign the heading, no longer clone the placeholder.
$assert(substr_count($componentPropTextHtml, '>Post Title<') === 2, 'component-prop-text-default-only-without-assignment');
$assert(str_contains($componentPropTextHtml, 'data-figma-node-id="instance:card-c/component:post-heading"'), 'component-prop-text-no-override-preserves-default-node');

$instanceCloneNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$instanceCloneNormalized = $instanceCloneNormalizer->normalize(array(
    'name'  => 'Instance Clone Pattern Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:icon',
            'type'     => 'COMPONENT',
            'name'     => 'Icon source',
            'key'      => 'icon-key',
            'children' => array(
                array(
                    'id'     => 'component:icon/vector',
                    'type'   => 'VECTOR',
                    'name'   => 'Icon vector',
                    'width'  => 10,
                    'height' => 10,
                    'pathData' => 'M0 0H10V10Z',
                ),
            ),
        ),
        array(
            'id'       => 'component:button-with-icon',
            'type'     => 'COMPONENT',
            'name'     => 'Button source',
            'key'      => 'button-with-icon-key',
            'children' => array(
                array(
                    'id'          => 'component:button-with-icon/icon-slot',
                    'type'        => 'INSTANCE',
                    'name'        => 'Icon slot',
                    'componentId' => 'icon-key',
                    'width'       => 10,
                    'height'      => 10,
                ),
                array(
                    'id'         => 'component:button-with-icon/label',
                    'type'       => 'TEXT',
                    'name'       => 'Button label',
                    'characters' => 'Default CTA',
                    'componentPropRefs' => array(
                        array(
                            'defID'                  => array('sessionID' => 500, 'localID' => 1),
                            'componentPropNodeField' => 'TEXT_DATA',
                        ),
                    ),
                ),
                array(
                    'id'       => 'component:button-with-icon/badge',
                    'type'     => 'RECTANGLE',
                    'name'     => 'Badge',
                    'width'    => 20,
                    'height'   => 20,
                ),
            ),
        ),
        array(
            'id'          => 'instance:button-with-icon',
            'type'        => 'INSTANCE',
            'name'        => 'Button instance',
            'componentId' => 'button-with-icon-key',
            'x'           => 40,
            'y'           => 50,
            'width'       => 160,
            'height'      => 48,
            'componentPropAssignments' => array(
                array(
                    'defID' => array('sessionID' => 500, 'localID' => 1),
                    'value' => array('textValue' => array('characters' => 'Assigned CTA')),
                ),
            ),
            'overrides'   => array(
                array(
                    'nodeId'    => 'component:button-with-icon/label',
                    'characters' => 'Explicit CTA',
                ),
                array(
                    'nodeId'    => 'component:button-with-icon/badge',
                    'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 72, 'm10' => 0, 'm11' => 1, 'm12' => 14),
                ),
            ),
        ),
    ),
));
$instanceClone = $instanceCloneNormalized['node_map']['instance:button-with-icon'] ?? array();
$instanceCloneIcon = array();
$instanceCloneLabel = array();
$instanceCloneBadge = array();
foreach ( is_array($instanceClone['children'] ?? null) ? $instanceClone['children'] : array() as $child ) {
    if ( ! is_array($child) ) {
        continue;
    }
    if ( 'component:button-with-icon/icon-slot' === ($child['figma_component_source_id'] ?? null) ) {
        $instanceCloneIcon = $child;
    } elseif ( 'component:button-with-icon/label' === ($child['figma_component_source_id'] ?? null) ) {
        $instanceCloneLabel = $child;
    } elseif ( 'component:button-with-icon/badge' === ($child['figma_component_source_id'] ?? null) ) {
        $instanceCloneBadge = $child;
    }
}
$instanceCloneIconVector = $instanceCloneIcon['children'][0] ?? array();
$assert('instance:button-with-icon/component:button-with-icon/icon-slot' === ($instanceCloneIcon['id'] ?? null), 'instance-clone-retargets-refreshed-nested-instance-root');
$assert('component:button-with-icon/icon-slot' === ($instanceCloneIcon['figma_component_source_id'] ?? null), 'instance-clone-preserves-nested-instance-source-id');
$assert('instance:button-with-icon/component:button-with-icon/icon-slot/component:icon/vector' === ($instanceCloneIconVector['id'] ?? null), 'instance-clone-refreshes-nested-source-child');
$assert('Explicit CTA' === ($instanceCloneLabel['figma_text']['characters'] ?? null), 'instance-clone-explicit-text-override-beats-component-property');
$assert(true === ($instanceClone['layout']['freeform'] ?? null), 'instance-clone-transform-override-marks-root-freeform');
$assert(72.0 === ($instanceCloneBadge['box']['x'] ?? null) && 14.0 === ($instanceCloneBadge['box']['y'] ?? null), 'instance-clone-transform-override-preserves-geometry');

$fontAwesomeIconNameResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Font Awesome Icon Name Fixture',
    'nodes' => array(
        array(
            'id'       => 'text:sparkles',
            'type'     => 'TEXT',
            'name'     => 'sparkles',
            'textData' => array('characters' => 'sparkles'),
            'fontName' => array('family' => 'Font Awesome 7 Pro', 'style' => 'Solid', 'postscript' => 'FontAwesome7Pro-Solid'),
        ),
        array(
            'id'       => 'text:circle-check',
            'type'     => 'TEXT',
            'name'     => 'circle-check',
            'textData' => array('characters' => 'circle-check'),
            'fontName' => array('family' => 'Font Awesome 7 Pro', 'style' => 'Regular', 'postscript' => 'FontAwesome7Pro-Regular'),
        ),
    ),
));
$fontAwesomeIconNameHtml = $fileContent($fontAwesomeIconNameResult, 'index.html');
$assert(str_contains($fontAwesomeIconNameHtml, '✦') && ! str_contains($fontAwesomeIconNameHtml, '>sparkles<'), 'font-awesome-sparkles-name-fallback');
$assert(str_contains($fontAwesomeIconNameHtml, '✓') && ! str_contains($fontAwesomeIconNameHtml, '>circle-check<'), 'font-awesome-circle-check-name-fallback');

$componentPlaceholderTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Component Placeholder Text Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:placeholder-button',
            'type'     => 'COMPONENT',
            'name'     => 'Placeholder button component',
            'key'      => 'placeholder-button-key',
            'children' => array(
                array(
                    'id'         => 'component:placeholder-button-label',
                    'type'       => 'TEXT',
                    'name'       => 'Button label',
                    'characters' => 'Button label',
                ),
            ),
        ),
        array(
            'id'          => 'instance:placeholder-button',
            'type'        => 'INSTANCE',
            'name'        => 'Button-color',
            'componentId' => 'placeholder-button-key',
        ),
    ),
), array('frame_id' => 'instance:placeholder-button'));
$componentPlaceholderTextHtml = $fileContent($componentPlaceholderTextResult, 'index.html');
$assert(str_contains($componentPlaceholderTextHtml, 'data-figma-node-id="instance:placeholder-button/component:placeholder-button-label" data-figma-node-name="Button label"></span>'), 'component-placeholder-button-label-hidden');

$unresolvedInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Unresolved Component Instance Fixture',
    'nodes' => array(
        array(
            'id'            => 'instance:missing',
            'type'          => 'INSTANCE',
            'name'          => 'Missing component instance',
            'mainComponent' => array('id' => 'missing-component'),
        ),
    ),
));

$unresolvedInstanceHtml = $fileContent($unresolvedInstanceResult, 'index.html');
$unresolvedInstanceReport = $unresolvedInstanceResult['source_reports']['figma']['scenegraph'] ?? array();
$unresolvedInstanceDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $unresolvedInstanceResult['diagnostics'] ?? array()
);

$assert(0 === ($unresolvedInstanceReport['component_definition_count'] ?? null), 'unresolved-instance-component-definition-count');
$assert(1 === ($unresolvedInstanceReport['instance_node_count'] ?? null), 'unresolved-instance-counts-instance');
$assert(0 === ($unresolvedInstanceReport['resolved_instance_count'] ?? null), 'unresolved-instance-counts-resolved');
$assert('instance:missing' === ($unresolvedInstanceReport['unresolved_component_references'][0]['instance_id'] ?? null), 'unresolved-instance-report-instance-id');
$assert('missing-component' === ($unresolvedInstanceReport['unresolved_component_references'][0]['component_id'] ?? null), 'unresolved-instance-report-component-id');
$assert(str_contains($unresolvedInstanceHtml, 'data-figma-node-id="instance:missing"'), 'unresolved-instance-preserves-instance-id');
$assert(in_array('figma_instance_component_unresolved', $unresolvedInstanceDiagnosticCodes, true), 'unresolved-instance-diagnostic');

$booleanVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Boolean Vector Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'           => 'boolean:1',
            'type'         => 'BOOLEAN_OPERATION',
            'name'         => 'Compound icon',
            'width'        => 10,
            'height'       => 10,
            'fillPaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
            'fillGeometry' => array(array('commandsBlob' => 0, 'windingRule' => 'NONZERO')),
            'children'     => array(
                array(
                    'id'         => 'boolean:2',
                    'type'       => 'VECTOR',
                    'name'       => 'Operand path',
                    'width'      => 10,
                    'height'     => 10,
                    'vectorData' => array('vectorNetworkBlob' => 0),
                ),
            ),
        ),
    ),
));
$booleanVectorHtml = $fileContent($booleanVectorResult, 'index.html');
$booleanVectorDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $booleanVectorResult['diagnostics'] ?? array()
);
$assert(str_contains($booleanVectorHtml, 'data-figma-vector="true"'), 'boolean-vector-parent-svg');
$assert(! str_contains($booleanVectorHtml, 'data-figma-node-id="boolean:2"'), 'boolean-vector-suppresses-operand-child');
$assert(! in_array('unsupported_vector_node_placeholder', $booleanVectorDiagnosticCodes, true), 'boolean-vector-no-placeholder-diagnostic');

$instanceVectorChildrenResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Instance Vector Children Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'icon:component',
            'type'     => 'COMPONENT',
            'name'     => 'Icon component',
            'key'      => 'icon-key',
            'children' => array(
                array(
                    'id'           => 'icon:vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Vector',
                    'width'        => 10,
                    'height'       => 10,
                    'fillGeometry' => array(array('commandsBlob' => 0, 'windingRule' => 'NONZERO')),
                ),
            ),
        ),
        array(
            'id'          => 'icon:one',
            'type'        => 'INSTANCE',
            'name'        => 'Icon one',
            'componentId' => 'icon-key',
        ),
        array(
            'id'          => 'icon:two',
            'type'        => 'INSTANCE',
            'name'        => 'Icon two',
            'componentId' => 'icon-key',
        ),
    ),
));
$instanceVectorChildrenHtml = $fileContent($instanceVectorChildrenResult, 'index.html');
$instanceVectorChildrenCss = $fileContent($instanceVectorChildrenResult, 'style.css');
$assert(str_contains($instanceVectorChildrenHtml, 'data-figma-node-id="icon:one/icon:vector"'), 'instance-vector-child-id-namespaced-one');
$assert(str_contains($instanceVectorChildrenHtml, 'data-figma-node-id="icon:two/icon:vector"'), 'instance-vector-child-id-namespaced-two');
$assert(strpos($instanceVectorChildrenHtml, 'data-figma-node-id="icon:one/icon:vector"') !== strpos($instanceVectorChildrenHtml, 'data-figma-node-id="icon:vector"'), 'instance-vector-child-source-id-is-not-reused-by-instance-one');
$assert(strpos($instanceVectorChildrenHtml, 'data-figma-node-id="icon:two/icon:vector"') !== strpos($instanceVectorChildrenHtml, 'data-figma-node-id="icon:vector"'), 'instance-vector-child-source-id-is-not-reused-by-instance-two');
$assert(3 === substr_count($instanceVectorChildrenHtml, 'data-figma-vector="true"'), 'instance-vector-children-render-with-definition');
// Authored CSS: both instance vector children share identical sizing, so they
// reference one shared `.vector` class rather than duplicating per-node rules,
// while each keeps its namespaced `figma-node-*` hook.
$assert(str_contains($instanceVectorChildrenCss, '.vector{width:10px;height:10px'), 'instance-vector-css-shared-class');
$assert(str_contains($instanceVectorChildrenHtml, 'class="figma-node-icon-one-icon-vector-vector vector"'), 'instance-vector-css-one');
$assert(str_contains($instanceVectorChildrenHtml, 'class="figma-node-icon-two-icon-vector-vector vector"'), 'instance-vector-css-two');

$instanceSimpleNetworkVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Instance Simple Network Vector Fixture',
    'blobs' => array(array('bytes' => $simpleRectNetworkBlob)),
    'nodes' => array(
        array(
            'id'       => 'simple-network-icon:component',
            'type'     => 'COMPONENT',
            'name'     => 'Simple network icon component',
            'key'      => 'simple-network-icon-key',
            'children' => array(
                array(
                    'id'         => 'simple-network-icon:vector',
                    'type'       => 'VECTOR',
                    'name'       => 'Vector 10',
                    'size'       => array('x' => 12, 'y' => 6),
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0.5, 'b' => 1))),
                    'vectorData' => array('vectorNetworkBlob' => 0),
                ),
            ),
        ),
        array(
            'id'          => 'simple-network-icon:instance',
            'type'        => 'INSTANCE',
            'name'        => 'Simple network icon instance',
            'componentId' => 'simple-network-icon-key',
        ),
    ),
));
$instanceSimpleNetworkVectorHtml = $fileContent($instanceSimpleNetworkVectorResult, 'index.html');
$instanceSimpleNetworkVectorDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $instanceSimpleNetworkVectorResult['diagnostics'] ?? array()
);
$assert(str_contains($instanceSimpleNetworkVectorHtml, 'data-figma-node-id="simple-network-icon:instance/simple-network-icon:vector"'), 'instance-simple-network-vector-child-id-namespaced');
$assert(str_contains($instanceSimpleNetworkVectorHtml, 'd="M0 0L12 0 12 6 0 6Z"') && str_contains($instanceSimpleNetworkVectorHtml, 'fill="#0080ff"'), 'instance-simple-network-vector-renders-size-derived-path');
$assert(! in_array('unsupported_vector_node_placeholder', $instanceSimpleNetworkVectorDiagnosticCodes, true), 'instance-simple-network-vector-no-placeholder-diagnostic');
$assert(! in_array('unsupported_vector_network_blob', $instanceSimpleNetworkVectorDiagnosticCodes, true), 'instance-simple-network-vector-no-network-diagnostic');

$nestedInstanceVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nested Instance Vector Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'nested-icon:component',
            'type'     => 'COMPONENT',
            'name'     => 'Icon component',
            'key'      => 'nested-icon-key',
            'children' => array(
                array(
                    'id'           => 'nested-icon:vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Vector',
                    'width'        => 10,
                    'height'       => 10,
                    'fillGeometry' => array(array('commandsBlob' => 0, 'windingRule' => 'NONZERO')),
                ),
            ),
        ),
        array(
            'id'       => 'nested-wrapper:component',
            'type'     => 'COMPONENT',
            'name'     => 'Wrapper component',
            'key'      => 'nested-wrapper-key',
            'children' => array(
                array(
                    'id'          => 'nested-wrapper:icon',
                    'type'        => 'INSTANCE',
                    'name'        => 'Nested icon',
                    'componentId' => 'nested-icon-key',
                ),
            ),
        ),
        array(
            'id'          => 'nested-wrapper:instance',
            'type'        => 'INSTANCE',
            'name'        => 'Wrapper instance',
            'componentId' => 'nested-wrapper-key',
        ),
    ),
));
$nestedInstanceVectorHtml = $fileContent($nestedInstanceVectorResult, 'index.html');
$assert(str_contains($nestedInstanceVectorHtml, 'data-figma-node-id="nested-wrapper:instance/nested-wrapper:icon/nested-icon:vector"'), 'nested-instance-vector-child-id-namespaced');
$assert(str_contains($nestedInstanceVectorHtml, 'data-figma-vector="true"'), 'nested-instance-vector-renders-svg');

$nestedInstanceSelectedOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nested Instance Selected Origin Fixture',
    'nodes' => array(
        array(
            'id'       => 'selected-origin:logo-component',
            'type'     => 'COMPONENT',
            'name'     => 'Logo component',
            'key'      => 'selected-origin-logo-key',
            'width'    => 40,
            'height'   => 20,
            'children' => array(
                array(
                    'id'     => 'selected-origin:logo-mark',
                    'type'   => 'RECTANGLE',
                    'name'   => 'Logo mark',
                    'width'  => 40,
                    'height' => 20,
                ),
            ),
        ),
        array(
            'id'       => 'selected-origin:header-component',
            'type'     => 'COMPONENT',
            'name'     => 'Header component',
            'key'      => 'selected-origin-header-key',
            'width'    => 1200,
            'height'   => 120,
            'children' => array(
                array(
                    'id'                  => 'selected-origin:nested-logo',
                    'type'                => 'INSTANCE',
                    'name'                => 'Nested logo',
                    'componentId'         => 'selected-origin-logo-key',
                    'x'                   => 120,
                    'y'                   => 32,
                    'width'               => 40,
                    'height'              => 20,
                    // The stale source node may still carry canvas geometry. The
                    // clone placement above is parent-local and must win.
                    'absoluteBoundingBox' => array('x' => -2948, 'y' => -362.5, 'width' => 40, 'height' => 20),
                ),
            ),
        ),
        array(
            'id'                  => 'selected-origin:frame',
            'type'                => 'FRAME',
            'name'                => 'Selected nonzero frame',
            'absoluteBoundingBox' => array('x' => -3068, 'y' => -394.5, 'width' => 1200, 'height' => 800),
            'children'            => array(
                array(
                    'id'                  => 'selected-origin:header-instance',
                    'type'                => 'INSTANCE',
                    'name'                => 'Header instance',
                    'componentId'         => 'selected-origin-header-key',
                    'absoluteBoundingBox' => array('x' => -3068, 'y' => -394.5, 'width' => 1200, 'height' => 120),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
), array('frame_id' => 'selected-origin:frame'));
$nestedInstanceSelectedOriginCss = $fileContent($nestedInstanceSelectedOriginResult, 'style.css');
$assert(str_contains($nestedInstanceSelectedOriginCss, '.figma-node-selected-origin-header-instance-selected-origin-nested-logo-nested-logo{width:40px;height:20px;position:absolute;left:120px;top:32px'), 'selected-origin-nested-instance-keeps-local-placement');
$assert(! str_contains($nestedInstanceSelectedOriginCss, 'left:-2948px;top:-362.5px'), 'selected-origin-nested-instance-no-page-origin-subtracted-placement');

$scaledVectorInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Scaled Vector Instance Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'scaled-icon:component',
            'type'     => 'COMPONENT',
            'name'     => 'Scaled icon component',
            'key'      => 'scaled-icon-key',
            'width'    => 10,
            'height'   => 10,
            'children' => array(
                array(
                    'id'           => 'scaled-icon:vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Vector',
                    'width'        => 10,
                    'height'       => 10,
                    'fillGeometry' => array(array('commandsBlob' => 0, 'windingRule' => 'NONZERO')),
                ),
            ),
        ),
        array(
            'id'          => 'scaled-icon:instance',
            'type'        => 'INSTANCE',
            'name'        => 'Scaled icon instance',
            'componentId' => 'scaled-icon-key',
            'width'       => 20,
            'height'      => 20,
        ),
    ),
));
$scaledVectorInstanceHtml = $fileContent($scaledVectorInstanceResult, 'index.html');
$scaledVectorInstanceCss = $fileContent($scaledVectorInstanceResult, 'style.css');
$assert(str_contains($scaledVectorInstanceCss, '.figma-node-scaled-icon-instance-scaled-icon-vector-vector{width:20px;height:20px'), 'scaled-vector-instance-child-css-scaled');
$assert(str_contains($scaledVectorInstanceHtml, '<g transform="scale(2 2)">'), 'scaled-vector-instance-svg-transform');

$effectsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Effects Fixture',
    'nodes' => array(
        array(
            'id'      => 'effects:1',
            'type'    => 'FRAME',
            'name'    => 'Effects frame',
            'width'   => 100,
            'height'  => 100,
            'effects' => array(
                array(
                    'type'   => 'DROP_SHADOW',
                    'offset' => array('x' => 0, 'y' => 6),
                    'radius' => 6,
                    'spread' => 0,
                    'color'  => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.16),
                ),
                array(
                    'type'   => 'INNER_SHADOW',
                    'offset' => array('x' => 1, 'y' => 2),
                    'radius' => 3,
                    'spread' => 4,
                    'color'  => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 0.5),
                ),
                array('type' => 'LAYER_BLUR', 'radius' => 2),
                array('type' => 'BACKGROUND_BLUR', 'radius' => 5),
            ),
            'children' => array(
                array(
                    'id'         => 'effects:2',
                    'type'       => 'TEXT',
                    'name'       => 'Shadow text',
                    'characters' => 'Shadow',
                    'effects'    => array(
                        array(
                            'type'   => 'DROP_SHADOW',
                            'offset' => array('x' => 1, 'y' => 1),
                            'radius' => 2,
                            'color'  => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.4),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$effectsCss = $fileContent($effectsResult, 'style.css');
$effectsDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $effectsResult['diagnostics'] ?? array()
);
$assert(str_contains($effectsCss, 'box-shadow:0px 6px 6px 0px rgba(0,0,0,0.16),inset 1px 2px 3px 4px rgba(255,0,0,0.5)'), 'effects-box-shadow-css');
$assert(str_contains($effectsCss, 'filter:blur(2px)'), 'effects-layer-blur-css');
$assert(str_contains($effectsCss, 'backdrop-filter:blur(5px)'), 'effects-background-blur-css');
$assert(str_contains($effectsCss, 'text-shadow:1px 1px 2px 0px rgba(0,0,0,0.4)'), 'effects-text-shadow-css');
$assert(! in_array('unsupported_figma_effect_type', $effectsDiagnosticCodes, true), 'effects-supported-no-diagnostic');

// ---------------------------------------------------------------------------
// Effects from a REAL Kiwi binary (#328): the field policy was starved, so
// shadows/blur never decoded even though the normalizer + emitter were written.
// DECODE the binary through the generic selective decoder, then prove the
// decoded Kiwi effects (carrying the `FOREGROUND_BLUR` token) emit the exact CSS.
// ---------------------------------------------------------------------------
$kiwiEffectsDecoder = new FigKiwiDecoder();
$kiwiEffectsSchema = $kiwiEffectsDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_effects_schema_fixture());
$assert(null !== ($kiwiEffectsSchema['schema'] ?? null), 'kiwi-effects-schema-decodes');
$kiwiEffectsMessage = $kiwiEffectsDecoder->decodeMessageSelective(
    blocks_engine_figma_transformer_kiwi_effects_message_fixture(),
    $kiwiEffectsSchema['schema'] ?? array()
);
$kiwiEffectsNodeChange = $kiwiEffectsMessage['message']['nodeChanges'][0] ?? array();
$kiwiDecodedEffects = is_array($kiwiEffectsNodeChange['effects'] ?? null) ? $kiwiEffectsNodeChange['effects'] : array();
$assert(2 === count($kiwiDecodedEffects), 'kiwi-effects-field-policy-decodes-effects-array');
$assert('DROP_SHADOW' === ($kiwiDecodedEffects[0]['type'] ?? null), 'kiwi-effects-decodes-drop-shadow-token');
$assert('FOREGROUND_BLUR' === ($kiwiDecodedEffects[1]['type'] ?? null), 'kiwi-effects-decodes-foreground-blur-token');
$assert(6 === (int) round((float) ($kiwiDecodedEffects[0]['offset']['y'] ?? 0)), 'kiwi-effects-decodes-shadow-offset');
$assert(8 === (int) round((float) ($kiwiDecodedEffects[1]['radius'] ?? 0)), 'kiwi-effects-decodes-blur-radius');

// EMIT: feed the decoded Kiwi effects verbatim through normalize -> emit and
// assert the exact box-shadow + filter CSS reaches style.css.
$kiwiEffectsRenderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Effects Fixture',
    'nodes' => array(
        array(
            'id'      => 'kiwi:effects-frame',
            'type'    => (string) ($kiwiEffectsNodeChange['type'] ?? 'FRAME'),
            'name'    => (string) ($kiwiEffectsNodeChange['name'] ?? 'Effects Frame'),
            'width'   => 200,
            'height'  => 120,
            'effects' => $kiwiDecodedEffects,
        ),
    ),
));
$kiwiEffectsCss = $fileContent($kiwiEffectsRenderResult, 'style.css');
$kiwiEffectsDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $kiwiEffectsRenderResult['diagnostics'] ?? array()
);
$assert(str_contains($kiwiEffectsCss, 'box-shadow:0px 6px 6px 0px rgba(0,0,0,0.5)'), 'kiwi-effects-emits-drop-shadow-css');
$assert(str_contains($kiwiEffectsCss, 'filter:blur(8px)'), 'kiwi-effects-emits-foreground-blur-css');
$assert(! in_array('unsupported_figma_effect_type', $kiwiEffectsDiagnosticCodes, true), 'kiwi-effects-foreground-blur-no-diagnostic');

$symbolInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Symbol Instance Fixture',
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 18, 'localID' => 96),
            'type'     => 'SYMBOL',
            'name'     => 'Legacy Symbol Button',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 18, 'localID' => 97),
                    'type'       => 'TEXT',
                    'name'       => 'Symbol label',
                    'characters' => 'Symbol label',
                ),
            ),
        ),
        array(
            'id'         => 'instance:symbol-button',
            'type'       => 'INSTANCE',
            'name'       => 'Legacy Symbol Instance',
            'symbolData' => array('symbolID' => array('sessionID' => 18, 'localID' => 96)),
        ),
    ),
));
$symbolInstanceHtml = $fileContent($symbolInstanceResult, 'index.html');
$symbolInstanceReport = $symbolInstanceResult['source_reports']['figma']['scenegraph'] ?? array();
$symbolInstanceDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $symbolInstanceResult['diagnostics'] ?? array()
);
$assert(1 === ($symbolInstanceReport['component_definition_count'] ?? null), 'symbol-instance-definition-count');
$assert(1 === ($symbolInstanceReport['instance_node_count'] ?? null), 'symbol-instance-instance-count');
$assert(1 === ($symbolInstanceReport['resolved_instance_count'] ?? null), 'symbol-instance-resolved-count');
$assert(str_contains($symbolInstanceHtml, 'data-figma-node-id="instance:symbol-button"'), 'symbol-instance-preserves-instance-id');
$assert(str_contains($symbolInstanceHtml, 'Symbol label'), 'symbol-instance-renders-symbol-children');
$assert(! in_array('figma_instance_component_unresolved', $symbolInstanceDiagnosticCodes, true), 'symbol-instance-no-unresolved-diagnostic');

$limitedSymbolInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Limited Symbol Instance Fixture',
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 28, 'localID' => 96),
            'type'     => 'SYMBOL',
            'name'     => 'Limited Symbol Button',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 28, 'localID' => 97),
                    'type'       => 'TEXT',
                    'name'       => 'Limited Symbol label',
                    'characters' => 'Limited symbol label',
                ),
            ),
        ),
        array(
            'id'       => 'limited:frame',
            'type'     => 'FRAME',
            'name'     => 'Limited Frame',
            'children' => array(
                array(
                    'id'         => 'limited:instance',
                    'type'       => 'INSTANCE',
                    'name'       => 'Limited Symbol Instance',
                    'symbolData' => array('symbolID' => array('sessionID' => 28, 'localID' => 96)),
                ),
            ),
        ),
    ),
), array('frame_id' => 'limited:frame', 'max_nodes' => 2));
$limitedSymbolInstanceHtml = $fileContent($limitedSymbolInstanceResult, 'index.html');
$limitedSymbolInstanceReport = $limitedSymbolInstanceResult['source_reports']['figma']['scenegraph'] ?? array();
$limitedSymbolInstanceDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $limitedSymbolInstanceResult['diagnostics'] ?? array()
);
$assert(1 === ($limitedSymbolInstanceReport['component_definition_count'] ?? null), 'limited-symbol-instance-definition-count');
$assert(1 === ($limitedSymbolInstanceReport['resolved_instance_count'] ?? null), 'limited-symbol-instance-resolved-count');
$assert(str_contains($limitedSymbolInstanceHtml, 'Limited symbol label'), 'limited-symbol-instance-renders-symbol-children');
$assert(! in_array('figma_instance_component_unresolved', $limitedSymbolInstanceDiagnosticCodes, true), 'limited-symbol-instance-no-unresolved-diagnostic');

$nestedSymbolInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nested Symbol Instance Fixture',
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 30, 'localID' => 1),
            'type'     => 'SYMBOL',
            'name'     => 'Nested Button Symbol',
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 1, 'b' => 0))),
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 30, 'localID' => 2),
                    'type'       => 'TEXT',
                    'name'       => 'Nested Button Label',
                    'characters' => 'Default nested label',
                ),
            ),
        ),
        array(
            'id'       => 'nested:root',
            'type'     => 'FRAME',
            'name'     => 'Nested root',
            'children' => array(
                array(
                    'id'         => 'nested:instance',
                    'type'       => 'INSTANCE',
                    'name'       => 'Nested Button Instance',
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
                    'symbolData' => array(
                        'symbolID' => array('sessionID' => 30, 'localID' => 1),
                        'symbolOverrides' => array(
                            array(
                                'guidPath' => array('guids' => array(array('sessionID' => 30, 'localID' => 2))),
                                'textData' => array('characters' => 'Nested override label'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$nestedSymbolInstanceHtml = $fileContent($nestedSymbolInstanceResult, 'index.html');
$nestedSymbolInstanceCss = $fileContent($nestedSymbolInstanceResult, 'style.css');
$nestedSymbolInstanceReport = $nestedSymbolInstanceResult['source_reports']['figma']['scenegraph'] ?? array();
$assert(1 === ($nestedSymbolInstanceReport['resolved_instance_count'] ?? null), 'nested-symbol-instance-resolved-count');
$assert(str_contains($nestedSymbolInstanceHtml, 'data-figma-node-id="nested:instance"'), 'nested-symbol-instance-preserves-instance-id');
$assert(str_contains($nestedSymbolInstanceHtml, 'Nested override label'), 'nested-symbol-instance-applies-symbol-text-override');
$assert(str_contains($nestedSymbolInstanceCss, '.figma-node-nested-instance-nested-button-instance{') && str_contains($nestedSymbolInstanceCss, 'background:#ff0000'), 'nested-symbol-instance-keeps-instance-fill');

$derivedSymbolInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Derived Symbol Overrides Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 40, 'localID' => 1),
            'type'     => 'SYMBOL',
            'name'     => 'Derived Symbol',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 40, 'localID' => 2),
                    'type'       => 'TEXT',
                    'name'       => 'Derived Label',
                    'characters' => 'Default',
                    'width'      => 40,
                    'height'     => 12,
                ),
                array(
                    'guid'         => array('sessionID' => 40, 'localID' => 3),
                    'type'         => 'VECTOR',
                    'name'         => 'Derived Icon',
                    'width'        => 5,
                    'height'       => 5,
                    'fillGeometry' => array(array('commandsBlob' => 0)),
                ),
            ),
        ),
        array(
            'id'                => 'derived:instance',
            'type'              => 'INSTANCE',
            'name'              => 'Derived Instance',
            'symbolData'        => array(
                'symbolID' => array('sessionID' => 40, 'localID' => 1),
                'symbolOverrides' => array(
                    array(
                        'guidPath' => array('guids' => array(array('sessionID' => 40, 'localID' => 2))),
                        'textData' => array('characters' => 'Override'),
                    ),
                ),
            ),
            'derivedSymbolData' => array(
                array(
                    'guidPath'        => array('guids' => array(array('sessionID' => 40, 'localID' => 2))),
                    'size'            => array('x' => 90, 'y' => 24),
                    'transform'       => array('m00' => 1, 'm01' => 0, 'm02' => 12, 'm10' => 0, 'm11' => 1, 'm12' => 6),
                    'derivedTextData' => array('layoutSize' => array('x' => 90, 'y' => 24)),
                ),
                array(
                    'guidPath'      => array('guids' => array(array('sessionID' => 40, 'localID' => 3))),
                    'transform'     => array('m00' => 1, 'm01' => 0, 'm02' => 110, 'm10' => 0, 'm11' => 1, 'm12' => 10),
                    'fillGeometry'  => array(array('commandsBlob' => 0)),
                ),
            ),
        ),
    ),
));
$derivedSymbolInstanceHtml = $fileContent($derivedSymbolInstanceResult, 'index.html');
$derivedSymbolInstanceCss = $fileContent($derivedSymbolInstanceResult, 'style.css');
$assert(str_contains($derivedSymbolInstanceHtml, 'data-figma-node-id="derived:instance/40:2"'), 'derived-symbol-instance-label-namespaced');
$assert(str_contains($derivedSymbolInstanceHtml, 'Override'), 'derived-symbol-instance-text-override');
$assert(str_contains($derivedSymbolInstanceHtml, 'data-figma-node-id="derived:instance/40:3"'), 'derived-symbol-instance-icon-namespaced');
$assert(str_contains($derivedSymbolInstanceHtml, 'd="M0 0L10 0 10 10Z"'), 'derived-symbol-instance-icon-geometry');
$assert(! str_contains($derivedSymbolInstanceHtml, '<g transform="scale'), 'derived-symbol-instance-icon-avoids-stale-scale');
$assert(str_contains($derivedSymbolInstanceCss, '.figma-node-derived-instance-40-2-derived-label{width:90px;height:24px;position:absolute;left:12px;top:6px'), 'derived-symbol-instance-label-size-position');
$assert(str_contains($derivedSymbolInstanceCss, '.figma-node-derived-instance-40-3-derived-icon{width:10px;height:10px;position:absolute;left:110px;top:10px'), 'derived-symbol-instance-icon-size-position');

// OVERRIDDEN INSTANCE CHILD CANVAS-GLOBAL COORDINATE BUG (#xxx).
//
// When an instance resolves a component and an override carries a positional
// `transform`, the m02/m12 values are canvas-global (absolute) coordinates —
// NOT parent-local offsets. A page frame at canvas x=12000 y=0 containing a
// Footer instance should resolve the Logo child's override transform m02=12120
// as CSS left:120px (= 12120 - 12000), NOT left:12120px (the raw canvas value).
//
// Before the fix, normalizeOverriddenInstanceChild stamped bare x/y from m02/m12
// onto the child, which normalizeLayoutBox then mislabeled coordinate_space='local',
// causing positionOffset() to emit the raw canvas value verbatim instead of
// subtracting the containing-block origin.
//
// The instance must have NO pre-populated children so that resolveInstances clones
// them from the component and runs applyInstanceOverridesToChildren, which is the
// code path that calls normalizeOverriddenInstanceChild.
$canvasGlobalOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Canvas-Global Override Coordinate Fixture',
    'nodes' => array(
        // Component master: Logo child has NO absoluteBoundingBox — only width/height.
        // The instance override will supply a canvas-global transform (m02=12120).
        // Without the fix, normalizeLayoutBox picks up the stamped bare x/y and labels
        // them coordinate_space='local', causing positionOffset() to emit 12120px verbatim.
        array(
            'id'       => 'cgoc:component',
            'type'     => 'COMPONENT',
            'name'     => 'Footer component',
            'key'      => 'footer-key',
            'absoluteBoundingBox' => array('x' => 12000, 'y' => 700, 'width' => 1440, 'height' => 200),
            'children' => array(
                array(
                    'id'    => 'cgoc:logo',
                    'type'  => 'RECTANGLE',
                    'name'  => 'Logo',
                    // No absoluteBoundingBox — the override transform is the only position source.
                    'width' => 160,
                    'height' => 60,
                ),
            ),
        ),
        // Page frame at canvas offset x=12000, containing the footer instance.
        // The instance has NO children — resolution will clone them from the component.
        array(
            'id'                  => 'cgoc:page',
            'type'                => 'FRAME',
            'name'                => 'Page',
            'absoluteBoundingBox' => array('x' => 12000, 'y' => 0, 'width' => 1440, 'height' => 900),
            'children'            => array(
                array(
                    'id'          => 'cgoc:instance',
                    'type'        => 'INSTANCE',
                    'name'        => 'Footer',
                    'componentId' => 'footer-key',
                    'absoluteBoundingBox' => array('x' => 12000, 'y' => 700, 'width' => 1440, 'height' => 200),
                    // Override: the Logo child's transform carries a canvas-global position
                    // (m02=12120 is an absolute canvas X). This mimics the FSE Pilot .fig
                    // pattern where m02=13842 leaked through as CSS left:13842px.
                    'overrides'   => array(
                        array(
                            'nodeId'    => 'cgoc:logo',
                            'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 12120, 'm10' => 0, 'm11' => 1, 'm12' => 860),
                        ),
                    ),
                    // No 'children' key — instance resolution clones from the component.
                ),
            ),
        ),
    ),
), array('frame_id' => 'cgoc:page'));
$canvasGlobalOverrideCss = $fileContent($canvasGlobalOverrideResult, 'style.css');
// The Logo node's CSS left must be page-relative (120px), NOT the raw canvas value (12120px).
// A value >= 1440 (canvas width) proves the bug: the raw canvas coordinate leaked through.
// The namespaced class is "cgoc-instance-cgoc-logo-logo" (instanceId/childId).
preg_match('/figma-node-cgoc-instance-cgoc-logo[^{]*\{[^}]*left:([\d.]+)px/', $canvasGlobalOverrideCss, $canvasGlobalLogoLeft);
$canvasGlobalLogoLeftPx = isset($canvasGlobalLogoLeft[1]) ? (float) $canvasGlobalLogoLeft[1] : -1.0;
$assert($canvasGlobalLogoLeftPx >= 0.0 && $canvasGlobalLogoLeftPx < 1440.0, 'overridden-instance-child-canvas-global-transform-localizes-x');

// DOCUMENT-MODE MULTI-PAGE ORIGIN REBASE (#360): emitSite resolves each page
// frame from scenegraph['node_map'], so render_document normalization must write
// page-localized frames back into node_map instead of only rebasing render nodes.
$documentModeNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$documentModeEmitter = new \Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter();
$documentModeScenegraph = array(
    'name'  => 'Document Mode Canvas-Global Override Fixture',
    'nodes' => array(
        array(
            'id'       => 'mpoc:canvas',
            'type'     => 'CANVAS',
            'name'     => 'Site Pages',
            'children' => array(
                array(
                    'id'       => 'mpoc:component',
                    'type'     => 'COMPONENT',
                    'name'     => 'Footer component',
                    'key'      => 'mpoc-footer-key',
                    'absoluteBoundingBox' => array('x' => 12000, 'y' => 700, 'width' => 1440, 'height' => 200),
                    'children' => array(
                        array('id' => 'mpoc:logo', 'type' => 'RECTANGLE', 'name' => 'Logo', 'width' => 160, 'height' => 60),
                    ),
                ),
                array(
                    'id'                  => 'mpoc:home',
                    'type'                => 'FRAME',
                    'name'                => 'Home',
                    'absoluteBoundingBox' => array('x' => 12000, 'y' => 0, 'width' => 1440, 'height' => 900),
                    'children'            => array(
                        array(
                            'id'          => 'mpoc:home-instance',
                            'type'        => 'INSTANCE',
                            'name'        => 'Footer',
                            'componentId' => 'mpoc-footer-key',
                            'absoluteBoundingBox' => array('x' => 12000, 'y' => 700, 'width' => 1440, 'height' => 200),
                            'overrides'   => array(
                                array('nodeId' => 'mpoc:logo', 'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 12120, 'm10' => 0, 'm11' => 1, 'm12' => 860)),
                            ),
                        ),
                    ),
                ),
                array(
                    'id'                  => 'mpoc:about',
                    'type'                => 'FRAME',
                    'name'                => 'About',
                    'absoluteBoundingBox' => array('x' => 24000, 'y' => 0, 'width' => 1440, 'height' => 900),
                    'children'            => array(
                        array(
                            'id'          => 'mpoc:about-instance',
                            'type'        => 'INSTANCE',
                            'name'        => 'Footer',
                            'componentId' => 'mpoc-footer-key',
                            'absoluteBoundingBox' => array('x' => 24000, 'y' => 700, 'width' => 1440, 'height' => 200),
                            'overrides'   => array(
                                array('nodeId' => 'mpoc:logo', 'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 24150, 'm10' => 0, 'm11' => 1, 'm12' => 860)),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
);
$documentModeNormalized = $documentModeNormalizer->normalize($documentModeScenegraph, array(
    'render_document' => true,
    'document_frame_ids' => array('mpoc:home', 'mpoc:about'),
));
$documentModeNodeMap = is_array($documentModeNormalized['node_map'] ?? null) ? $documentModeNormalized['node_map'] : array();
$documentModeHomeFrame = is_array($documentModeNodeMap['mpoc:home'] ?? null) ? $documentModeNodeMap['mpoc:home'] : array();
$documentModeAboutFrame = is_array($documentModeNodeMap['mpoc:about'] ?? null) ? $documentModeNodeMap['mpoc:about'] : array();
$documentModeHomeInstance = is_array($documentModeHomeFrame['children'][0] ?? null) ? $documentModeHomeFrame['children'][0] : array();
$documentModeAboutInstance = is_array($documentModeAboutFrame['children'][0] ?? null) ? $documentModeAboutFrame['children'][0] : array();
$documentModeHomeLogo = is_array($documentModeHomeInstance['children'][0] ?? null) ? $documentModeHomeInstance['children'][0] : array();
$documentModeAboutLogo = is_array($documentModeAboutInstance['children'][0] ?? null) ? $documentModeAboutInstance['children'][0] : array();
$assert(0.0 === ($documentModeHomeFrame['box']['x'] ?? null) && 0.0 === ($documentModeHomeFrame['box']['y'] ?? null), 'document-mode-home-page-root-origin-zero');
$assert(0.0 === ($documentModeAboutFrame['box']['x'] ?? null) && 0.0 === ($documentModeAboutFrame['box']['y'] ?? null), 'document-mode-about-page-root-origin-zero');
$assert('local' === ($documentModeHomeFrame['box']['coordinate_space'] ?? null) && 'page' === ($documentModeHomeFrame['box']['local_origin'] ?? null), 'document-mode-home-page-root-marked-page-local');
$assert('local' === ($documentModeAboutFrame['box']['coordinate_space'] ?? null) && 'page' === ($documentModeAboutFrame['box']['local_origin'] ?? null), 'document-mode-about-page-root-marked-page-local');
$assert(0.0 === ($documentModeHomeInstance['box']['x'] ?? null) && 700.0 === ($documentModeHomeInstance['box']['y'] ?? null), 'document-mode-home-instance-subtracts-page-origin');
$assert(0.0 === ($documentModeAboutInstance['box']['x'] ?? null) && 700.0 === ($documentModeAboutInstance['box']['y'] ?? null), 'document-mode-about-instance-subtracts-page-origin');
$assert(120.0 === ($documentModeHomeLogo['box']['x'] ?? null) && 860.0 === ($documentModeHomeLogo['box']['y'] ?? null), 'document-mode-home-override-child-subtracts-page-origin');
$assert(150.0 === ($documentModeAboutLogo['box']['x'] ?? null) && 860.0 === ($documentModeAboutLogo['box']['y'] ?? null), 'document-mode-about-override-child-subtracts-page-origin');
$documentModeSite = $documentModeEmitter->emitSite($documentModeNormalized, array(
    'pages' => array(
        array('frame_id' => 'mpoc:home', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true),
        array('frame_id' => 'mpoc:about', 'name' => 'About', 'path' => 'about.html'),
    ),
));
$documentModeCss = $fileContent($documentModeSite, 'style.css');
preg_match('/figma-node-mpoc-home-instance-mpoc-logo[^{]*\{[^}]*left:([\d.]+)px/', $documentModeCss, $documentModeHomeLogoLeft);
preg_match('/figma-node-mpoc-about-instance-mpoc-logo[^{]*\{[^}]*left:([\d.]+)px/', $documentModeCss, $documentModeAboutLogoLeft);
$documentModeHomeLogoLeftPx = isset($documentModeHomeLogoLeft[1]) ? (float) $documentModeHomeLogoLeft[1] : -1.0;
$documentModeAboutLogoLeftPx = isset($documentModeAboutLogoLeft[1]) ? (float) $documentModeAboutLogoLeft[1] : -1.0;
$assert($documentModeHomeLogoLeftPx >= 0.0 && $documentModeHomeLogoLeftPx < 1440.0, 'document-mode-multi-page-overridden-instance-child-localizes-home-x');
$assert($documentModeAboutLogoLeftPx >= 0.0 && $documentModeAboutLogoLeftPx < 1440.0, 'document-mode-multi-page-overridden-instance-child-localizes-about-x');
$assert(! str_contains($documentModeCss, 'left:12120px') && ! str_contains($documentModeCss, 'left:24150px'), 'document-mode-multi-page-css-omits-canvas-global-lefts');

// Real anchor tags: a TEXT node carrying a URL hyperlink emits a real <a href>.
$urlLinkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Url Link Fixture',
    'nodes' => array(
        array(
            'id'       => 'link:1',
            'type'     => 'FRAME',
            'name'     => 'Footer',
            'width'    => 1200,
            'height'   => 200,
            'children' => array(
                array(
                    'id'         => 'link:2',
                    'type'       => 'TEXT',
                    'name'       => 'External link',
                    'characters' => 'Visit Automattic',
                    'hyperlink'  => array('type' => 'URL', 'url' => 'https://automattic.com/about'),
                ),
            ),
        ),
    ),
));
$urlLinkHtml = $fileContent($urlLinkResult, 'index.html');
$urlLinkCss = $fileContent($urlLinkResult, 'style.css');
$urlLinks = $urlLinkResult['source_reports']['figma']['html']['transform_diagnostics']['links'] ?? array();
$assert(str_contains($urlLinkHtml, '<a class="figma-link" href="https://automattic.com/about" data-figma-link-type="url">'), 'url-hyperlink-emits-anchor');
$assert(str_contains($urlLinkHtml, '<a class="figma-link" href="https://automattic.com/about" data-figma-link-type="url"><p class="figma-node-link-2-external-link"'), 'url-hyperlink-wraps-element-transparently');
$assert(str_contains($urlLinkCss, 'a.figma-link{display:contents'), 'figma-link-display-contents-rule');
$assert(1 === ($urlLinks['sources_found'] ?? null) && 1 === ($urlLinks['anchors_emitted'] ?? null) && 1 === ($urlLinks['url_links'] ?? null) && 0 === ($urlLinks['unresolved'] ?? null), 'url-hyperlink-link-coverage');

// Real anchor tags: a NODE/prototype link resolving to a planned frame emits the slug href.
$navScenegraph = array(
    'name'  => 'Nav Link Fixture',
    'nodes' => array(
        array(
            'id'       => 'nav:home',
            'type'     => 'FRAME',
            'name'     => 'Home',
            'width'    => 1280,
            'height'   => 900,
            'children' => array(
                array(
                    'id'         => 'nav:home-link',
                    'type'       => 'TEXT',
                    'name'       => 'About nav item',
                    'characters' => 'About',
                    'hyperlink'  => array('type' => 'NODE', 'nodeID' => 'nav:about'),
                ),
                array(
                    'id'         => 'nav:proto-link',
                    'type'       => 'FRAME',
                    'name'       => 'CTA button',
                    'width'      => 160,
                    'height'     => 48,
                    'reactions'  => array(
                        array(
                            'trigger' => array('type' => 'ON_CLICK'),
                            'action'  => array('type' => 'NODE', 'navigation' => 'NAVIGATE', 'destinationId' => 'nav:about'),
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'       => 'nav:about',
            'type'     => 'FRAME',
            'name'     => 'About',
            'width'    => 1280,
            'height'   => 900,
            'children' => array(
                array('id' => 'nav:about-title', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About us'),
            ),
        ),
    ),
);
$navResult = blocks_engine_figma_transformer_transform_scenegraph($navScenegraph, array('include_all_pages' => true, 'entry_frame_id' => 'nav:home'));
$navHomeHtml = $fileContent($navResult, 'index.html');
$assert(str_contains($navHomeHtml, '<a class="figma-link" href="about.html" data-figma-link-type="node">'), 'node-hyperlink-resolves-to-slug');
$assert(2 === substr_count($navHomeHtml, 'href="about.html"'), 'node-and-prototype-links-both-resolve');

// Real anchor tags: an unresolved NODE link is counted in the diagnostic and emitted as a placeholder anchor.
$unresolvedResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Unresolved Link Fixture',
    'nodes' => array(
        array(
            'id'       => 'dead:1',
            'type'     => 'FRAME',
            'name'     => 'Nav',
            'width'    => 1200,
            'height'   => 120,
            'children' => array(
                array(
                    'id'         => 'dead:2',
                    'type'       => 'TEXT',
                    'name'       => 'Broken link',
                    'characters' => 'Missing page',
                    'hyperlink'  => array('type' => 'NODE', 'nodeID' => 'does:not:exist'),
                ),
            ),
        ),
    ),
));
$unresolvedHtml = $fileContent($unresolvedResult, 'index.html');
$unresolvedLinks = $unresolvedResult['source_reports']['figma']['html']['transform_diagnostics']['links'] ?? array();
$unresolvedSignalCodes = $artifactQualitySignalCodes($unresolvedResult);
$unresolvedDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $unresolvedResult['diagnostics'] ?? array()
);
$assert(str_contains($unresolvedHtml, '<a class="figma-link" href="#" data-figma-link-type="node">'), 'unresolved-node-link-emits-placeholder-anchor');
$assert(1 === ($unresolvedLinks['unresolved'] ?? null) && 1 === ($unresolvedLinks['node_links'] ?? null), 'unresolved-link-counted-in-coverage');
$assert('does:not:exist' === ($unresolvedLinks['unresolved_targets'][0]['target_node_id'] ?? null), 'unresolved-link-records-target-node-id');
$assert(in_array('link_target_unresolved', $unresolvedSignalCodes, true), 'unresolved-link-artifact-quality-signal');
$assert(in_array('link_target_unresolved', $unresolvedDiagnosticCodes, true), 'unresolved-link-diagnostic-code');

// ---------------------------------------------------------------------------
// Figma links from .fig Kiwi shapes (#328): the normalizer + emitter turn the
// raw decoded `hyperlink` struct and `prototypeInteractions` list (Kiwi field
// names, distinct from the REST `reactions`) into real <a href> anchors.
// ---------------------------------------------------------------------------

// (a) A node carrying the Kiwi `Hyperlink` shape ({url} with no REST `type`)
// emits a real URL anchor.
$kiwiHyperlinkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Hyperlink Fixture',
    'nodes' => array(
        array(
            'id'       => 'kiwilink:1',
            'type'     => 'FRAME',
            'name'     => 'Footer',
            'width'    => 1200,
            'height'   => 200,
            'children' => array(
                array(
                    'id'         => 'kiwilink:2',
                    'type'       => 'TEXT',
                    'name'       => 'External link',
                    'characters' => 'Visit Automattic',
                    'hyperlink'  => array('url' => 'https://automattic.com/work'),
                ),
            ),
        ),
    ),
));
$kiwiHyperlinkHtml = $fileContent($kiwiHyperlinkResult, 'index.html');
$assert(str_contains($kiwiHyperlinkHtml, '<a class="figma-link" href="https://automattic.com/work" data-figma-link-type="url">'), 'kiwi-hyperlink-url-emits-anchor');

// (b) A node carrying an ON_CLICK `prototypeInteractions` entry whose action is
// a Kiwi URL connection (connectionType/connectionURL) emits a real URL anchor.
$kiwiReactionResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Reaction Fixture',
    'nodes' => array(
        array(
            'id'       => 'kiwireact:1',
            'type'     => 'FRAME',
            'name'     => 'Home',
            'width'    => 1280,
            'height'   => 900,
            'children' => array(
                array(
                    'id'                    => 'kiwireact:cta',
                    'type'                  => 'FRAME',
                    'name'                  => 'CTA button',
                    'width'                 => 160,
                    'height'                => 48,
                    'prototypeInteractions' => array(
                        array(
                            'event'   => array('interactionType' => 'ON_CLICK'),
                            'actions' => array(
                                array('connectionType' => 'URL', 'connectionURL' => 'https://automattic.com/cta'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$kiwiReactionHtml = $fileContent($kiwiReactionResult, 'index.html');
$assert(str_contains($kiwiReactionHtml, '<a class="figma-link" href="https://automattic.com/cta" data-figma-link-type="url">'), 'kiwi-prototype-interaction-url-emits-anchor');

// DECODE: the extended field policy carries `hyperlink` and the Kiwi
// `prototypeInteractions` list through the REAL generic decoder, resolving the
// connection enums to their token strings and the GUID destination struct.
$linkDecoder = new FigKiwiDecoder();
$linkSchema = $linkDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_link_schema_fixture());
$assert(null !== ($linkSchema['schema'] ?? null), 'link-kiwi-schema-decodes');
$linkMessage = $linkDecoder->decodeMessageSelective(
    blocks_engine_figma_transformer_kiwi_link_message_fixture(),
    $linkSchema['schema'] ?? array()
);
$linkNodeChanges = $linkMessage['message']['nodeChanges'] ?? array();
$assert('https://example.com/about' === ($linkNodeChanges[0]['hyperlink']['url'] ?? null), 'link-field-policy-carries-hyperlink-url');
$linkInteraction = $linkNodeChanges[1]['prototypeInteractions'][0] ?? array();
$assert('ON_CLICK' === ($linkInteraction['event']['interactionType'] ?? null), 'link-field-policy-carries-interaction-trigger');
$assert('URL' === ($linkInteraction['actions'][0]['connectionType'] ?? null), 'link-field-policy-carries-connection-type');
$assert('https://example.com/cta' === ($linkInteraction['actions'][0]['connectionURL'] ?? null), 'link-field-policy-carries-connection-url');
$assert('INTERNAL_NODE' === ($linkInteraction['actions'][1]['connectionType'] ?? null), 'link-field-policy-carries-node-connection-type');
$assert(array('sessionID' => 7, 'localID' => 42) === ($linkInteraction['actions'][1]['transitionNodeID'] ?? null), 'link-field-policy-carries-transition-node-guid');

// ---------------------------------------------------------------------------
// Figma Dev Mode status (#280): decode -> normalize -> select -> diagnose.
// ---------------------------------------------------------------------------

// DECODE: the extended field policy carries sectionStatus through the REAL
// generic Kiwi decoder, and the enum resolves to its token string.
$devStatusDecoder = new FigKiwiDecoder();
$devStatusSchema = $devStatusDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_dev_status_schema_fixture());
$assert(null !== ($devStatusSchema['schema'] ?? null), 'dev-status-kiwi-schema-decodes');
$devStatusMessage = $devStatusDecoder->decodeMessageSelective(
    blocks_engine_figma_transformer_kiwi_dev_status_message_fixture(),
    $devStatusSchema['schema'] ?? array()
);
$devStatusNodeChange = $devStatusMessage['message']['nodeChanges'][0] ?? array();
$assert('DOCUMENT' === ($devStatusMessage['message']['type'] ?? null), 'dev-status-kiwi-message-root-decodes');
$assert('SECTION' === ($devStatusNodeChange['type'] ?? null), 'dev-status-kiwi-section-node-decodes');
$assert('COMPLETED' === ($devStatusNodeChange['sectionStatus'] ?? null), 'dev-status-field-policy-carries-section-status');

// Stroke geometry (#328): the extended field policy must carry strokeWeight,
// strokeAlign and dashPattern through the REAL generic Kiwi decoder. Before the
// whitelist fix these were skipped and every border defaulted to 1px.
$strokeDecoder = new FigKiwiDecoder();
$strokeSchema  = $strokeDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_stroke_schema_fixture());
$assert(null !== ($strokeSchema['schema'] ?? null), 'stroke-kiwi-schema-decodes');
$strokeMessage = $strokeDecoder->decodeMessageSelective(
    blocks_engine_figma_transformer_kiwi_stroke_message_fixture(),
    $strokeSchema['schema'] ?? array()
);
$strokeNodeChange = $strokeMessage['message']['nodeChanges'][0] ?? array();
$assert('RECTANGLE' === ($strokeNodeChange['type'] ?? null), 'stroke-kiwi-node-decodes');
$assert(3.0 === round((float) ($strokeNodeChange['strokeWeight'] ?? 0.0), 4), 'stroke-field-policy-carries-stroke-weight');
$assert('INSIDE' === ($strokeNodeChange['strokeAlign'] ?? null), 'stroke-field-policy-carries-stroke-align');
$assert(
    array(4.0, 2.0) === array_map(static fn ($value): float => round((float) $value, 4), is_array($strokeNodeChange['dashPattern'] ?? null) ? $strokeNodeChange['dashPattern'] : array()),
    'stroke-field-policy-carries-dash-pattern'
);

// Component-property text overrides: the normalizer already resolves instance
// text assignments from these Kiwi fields, so selective decode must carry the
// exact nested structures instead of dropping them for oversized messages.
$componentPropDecoder = new FigKiwiDecoder();
$componentPropSchema = $componentPropDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_component_prop_schema_fixture());
$assert(null !== ($componentPropSchema['schema'] ?? null), 'component-prop-kiwi-schema-decodes');
$componentPropMessage = $componentPropDecoder->decodeMessageSelective(
    blocks_engine_figma_transformer_kiwi_component_prop_message_fixture(),
    $componentPropSchema['schema'] ?? array()
);
$componentPropNodeChange = $componentPropMessage['message']['nodeChanges'][0] ?? array();
$componentPropAssignment = $componentPropNodeChange['componentPropAssignments'][0] ?? array();
$componentPropRef = $componentPropNodeChange['componentPropRefs'][0] ?? array();
$assert(array('sessionID' => 9, 'localID' => 10) === ($componentPropAssignment['defID'] ?? null), 'component-prop-field-policy-carries-assignment-def-id');
$assert('Selected label' === ($componentPropAssignment['value']['textValue']['characters'] ?? null), 'component-prop-field-policy-carries-text-value');
$assert(array('sessionID' => 9, 'localID' => 10) === ($componentPropRef['defID'] ?? null), 'component-prop-field-policy-carries-ref-def-id');
$assert('TEXT_DATA' === ($componentPropRef['componentPropNodeField'] ?? null), 'component-prop-field-policy-carries-text-ref-field');
$assert(! array_key_exists('pluginData', $componentPropNodeChange), 'component-prop-field-policy-skips-adjacent-plugin-data');

// NORMALIZE: raw sectionStatus tokens map onto a clean dev_status with the raw
// value carried for auditability.
$devStatusNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$devStatusSource = array(
    'name'  => 'Dev Status Site',
    'nodes' => array(
        array(
            'id'       => 'page:dev',
            'type'     => 'CANVAS',
            'name'     => 'Pages',
            'children' => array(
                array(
                    'id'            => 'section:ready',
                    'type'          => 'SECTION',
                    'name'          => 'Ready section',
                    'sectionStatus' => 'BUILD',
                    'children'      => array(
                        array(
                            'id'       => 'frame:ready-home',
                            'type'     => 'FRAME',
                            'name'     => 'Ready Home',
                            'width'    => 1440,
                            'height'   => 1400,
                            'children' => array(
                                array('id' => 'text:ready', 'type' => 'TEXT', 'name' => 'Ready title', 'characters' => 'Ready Hero'),
                            ),
                        ),
                    ),
                ),
                array(
                    'id'                => 'section:done',
                    'type'              => 'SECTION',
                    'name'              => 'Done section',
                    'sectionStatusInfo' => array('status' => 'COMPLETED'),
                    'children'          => array(
                        array(
                            'id'       => 'frame:done-about',
                            'type'     => 'FRAME',
                            'name'     => 'Done About',
                            'width'    => 1440,
                            'height'   => 1300,
                            'children' => array(
                                array('id' => 'text:done', 'type' => 'TEXT', 'name' => 'Done title', 'characters' => 'Done Hero'),
                            ),
                        ),
                    ),
                ),
                array(
                    'id'       => 'frame:wip',
                    'type'     => 'FRAME',
                    'name'     => 'WIP Draft',
                    'width'    => 1440,
                    'height'   => 1200,
                    'children' => array(
                        array('id' => 'text:wip', 'type' => 'TEXT', 'name' => 'WIP title', 'characters' => 'WIP Hero'),
                    ),
                ),
            ),
        ),
    ),
);
$devStatusNormalized = $devStatusNormalizer->normalize($devStatusSource);
$devStatusNodeMap = is_array($devStatusNormalized['node_map'] ?? null) ? $devStatusNormalized['node_map'] : array();
$assert('ready_for_dev' === ($devStatusNodeMap['section:ready']['dev_status'] ?? null), 'dev-status-normalizes-ready-for-dev');
$assert('BUILD' === ($devStatusNodeMap['section:ready']['dev_status_raw'] ?? null), 'dev-status-carries-raw-build-token');
$assert('completed' === ($devStatusNodeMap['section:done']['dev_status'] ?? null), 'dev-status-normalizes-completed');
$assert('COMPLETED' === ($devStatusNodeMap['section:done']['dev_status_raw'] ?? null), 'dev-status-carries-raw-completed-token');
$assert(! array_key_exists('dev_status', $devStatusNodeMap['frame:wip'] ?? array()), 'dev-status-absent-on-unmarked-frame');

// SELECT: dev-status is the PRIMARY signal — marked frames selected (completed
// first), unmarked WIP frame skipped, and selection_source records the signal.
$devStatusPlanner = new ScenegraphPagePlanner();
$devStatusPlan = $devStatusPlanner->plan($devStatusSource, array('include_all_pages' => true));
$devStatusPlanFrameIds = array_map(
    static fn (array $page): string => (string) ($page['frame_id'] ?? ''),
    is_array($devStatusPlan['pages'] ?? null) ? $devStatusPlan['pages'] : array()
);
$assert('dev_status' === ($devStatusPlan['selection_source'] ?? null), 'dev-status-drives-selection-source');
$assert(in_array('frame:ready-home', $devStatusPlanFrameIds, true), 'dev-status-selects-ready-frame');
$assert(in_array('frame:done-about', $devStatusPlanFrameIds, true), 'dev-status-selects-completed-frame');
$assert(! in_array('frame:wip', $devStatusPlanFrameIds, true), 'dev-status-skips-unmarked-wip-frame');
$assert('frame:done-about' === ($devStatusPlanFrameIds[0] ?? null), 'dev-status-completed-frame-ranks-first');

// DIAGNOSE: coverage report + selection_source populated and emitted.
$devStatusCoverage = is_array($devStatusPlan['dev_status_coverage'] ?? null) ? $devStatusPlan['dev_status_coverage'] : array();
$assert(true === ($devStatusCoverage['file_has_dev_status'] ?? null), 'dev-status-coverage-flags-presence');
$assert(1 === ($devStatusCoverage['sections']['ready_for_dev'] ?? null), 'dev-status-coverage-counts-ready-section');
$assert(1 === ($devStatusCoverage['sections']['completed'] ?? null), 'dev-status-coverage-counts-completed-section');
$assert(1 === ($devStatusCoverage['frames_effective']['ready_for_dev'] ?? null), 'dev-status-coverage-counts-ready-frame');
$assert(1 === ($devStatusCoverage['frames_effective']['completed'] ?? null), 'dev-status-coverage-counts-completed-frame');
$devStatusPlanDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    is_array($devStatusPlan['diagnostics'] ?? null) ? $devStatusPlan['diagnostics'] : array()
);
$assert(in_array('figma_dev_status_coverage', $devStatusPlanDiagnosticCodes, true), 'dev-status-coverage-diagnostic-emitted');

// FALLBACK: with NO dev-status anywhere, heuristics drive selection unchanged.
$heuristicSource = array(
    'name'  => 'Heuristic Site',
    'nodes' => array(
        array(
            'id'       => 'page:heur',
            'type'     => 'CANVAS',
            'name'     => 'Pages',
            'children' => array(
                array(
                    'id'       => 'frame:heur-home',
                    'type'     => 'FRAME',
                    'name'     => 'Home',
                    'width'    => 1440,
                    'height'   => 1400,
                    'children' => array(
                        array('id' => 'text:heur', 'type' => 'TEXT', 'name' => 'Heur title', 'characters' => 'Heur Hero'),
                    ),
                ),
            ),
        ),
    ),
);
$heuristicPlan = $devStatusPlanner->plan($heuristicSource, array('include_all_pages' => true));
$heuristicFrameIds = array_map(
    static fn (array $page): string => (string) ($page['frame_id'] ?? ''),
    is_array($heuristicPlan['pages'] ?? null) ? $heuristicPlan['pages'] : array()
);
$heuristicDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    is_array($heuristicPlan['diagnostics'] ?? null) ? $heuristicPlan['diagnostics'] : array()
);
$assert('heuristic' === ($heuristicPlan['selection_source'] ?? null), 'no-dev-status-falls-back-to-heuristic');
$assert(false === ($heuristicPlan['dev_status_coverage']['file_has_dev_status'] ?? null), 'no-dev-status-coverage-flags-absence');
$assert(array('frame:heur-home') === $heuristicFrameIds, 'no-dev-status-selects-heuristic-frame');
$assert(! in_array('figma_dev_status_coverage', $heuristicDiagnosticCodes, true), 'no-dev-status-omits-coverage-diagnostic');

// MATRIX: the standalone selector reports the driving signal and prefers
// dev-status candidates when present.
$matrixDevStatusCandidates = array(
    array('id' => 'm:ready', 'name' => 'Ready Home', 'type' => 'FRAME', 'score' => 100, 'width' => 1440, 'height' => 1400, 'text_count' => 4, 'dev_status' => 'ready_for_dev', 'parent' => array('type' => 'SECTION'), 'page' => array('name' => 'Pages')),
    array('id' => 'm:done', 'name' => 'Done About', 'type' => 'FRAME', 'score' => 90, 'width' => 1440, 'height' => 1300, 'text_count' => 3, 'dev_status' => 'completed', 'parent' => array('type' => 'SECTION'), 'page' => array('name' => 'Pages')),
    array('id' => 'm:wip', 'name' => 'WIP Draft', 'type' => 'FRAME', 'score' => 500, 'width' => 1440, 'height' => 1200, 'text_count' => 5, 'parent' => array('type' => 'CANVAS'), 'page' => array('name' => 'Pages')),
);
$assert('dev_status' === matrix_selection_source($matrixDevStatusCandidates), 'matrix-reports-dev-status-source');
$matrixDevStatusSelection = matrix_select_frame_ids(array('candidates' => $matrixDevStatusCandidates), 5);
$assert(in_array('m:done', $matrixDevStatusSelection, true) && in_array('m:ready', $matrixDevStatusSelection, true), 'matrix-selects-dev-status-frames');
$assert(! in_array('m:wip', $matrixDevStatusSelection, true), 'matrix-skips-unmarked-frame-despite-higher-score');
$assert('heuristic' === matrix_selection_source(array(
    array('id' => 'h:home', 'name' => 'Home', 'type' => 'FRAME', 'score' => 100, 'parent' => array('type' => 'CANVAS'), 'page' => array('name' => 'Pages')),
)), 'matrix-reports-heuristic-source-without-dev-status');

// INSPECT: candidates must surface the frame's own dev_status so the matrix
// frame-selector (which keys on $candidate['dev_status']) can prefer dev-marked
// frames. Without this the inspector handed the selector statusless candidates,
// so selection_source stayed 'heuristic' even when the file had dev statuses.
$inspectorDevStatusSource = array(
    'name'  => 'Inspector Dev Status',
    'nodes' => array(
        array(
            'id'       => 'canvas:1',
            'type'     => 'CANVAS',
            'name'     => 'Page 1',
            'children' => array(
                array('id' => 'frame:build', 'type' => 'FRAME', 'name' => 'Marked Home', 'width' => 1440, 'height' => 1400, 'sectionStatus' => 'BUILD', 'children' => array()),
                array('id' => 'frame:plain', 'type' => 'FRAME', 'name' => 'Plain Page', 'width' => 1440, 'height' => 1200, 'children' => array()),
            ),
        ),
    ),
);
$inspectorDevStatusResult = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector() )->inspect($inspectorDevStatusSource);
$inspectorCandidates = array();
foreach ( ( is_array($inspectorDevStatusResult['candidates'] ?? null) ? $inspectorDevStatusResult['candidates'] : array() ) as $inspectorCandidate ) {
    if ( is_array($inspectorCandidate) && isset($inspectorCandidate['id']) ) {
        $inspectorCandidates[(string) $inspectorCandidate['id']] = $inspectorCandidate;
    }
}
$assert('ready_for_dev' === ($inspectorCandidates['frame:build']['dev_status'] ?? null), 'inspector-surfaces-dev-status-on-candidate');
$assert(! array_key_exists('dev_status', $inspectorCandidates['frame:plain'] ?? array()), 'inspector-omits-dev-status-on-unmarked-candidate');
$assert('dev_status' === matrix_selection_source(array_values($inspectorCandidates)), 'inspector-candidates-drive-dev-status-selection');

// INSPECT: candidates must surface role + page_type so the matrix frame-selector
// can exclude design-system frames and prefer real pages by WP template type.
// Same plumbing pattern as dev_status (#283): the classifier runs on each
// candidate at inspect time so the selector sees role/page_type without needing
// to re-run classification downstream.
$inspectorRoleSource = array(
    'name'  => 'Inspector Role Classification',
    'nodes' => array(
        array(
            'id'       => 'canvas:rc',
            'type'     => 'CANVAS',
            'name'     => 'Pages',
            'children' => array(
                array('id' => 'frame:sg', 'type' => 'FRAME', 'name' => 'Style Guide', 'width' => 1440, 'height' => 2000, 'children' => array(
                    array('id' => 'sg:text', 'type' => 'TEXT', 'name' => 'Colors', 'characters' => 'Brand Colors'),
                )),
                array('id' => 'frame:home', 'type' => 'FRAME', 'name' => 'Home Page', 'width' => 1440, 'height' => 1600, 'children' => array(
                    array('id' => 'home:text', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                )),
                array('id' => 'frame:post', 'type' => 'FRAME', 'name' => 'Blog Post', 'width' => 1440, 'height' => 2400, 'children' => array(
                    array('id' => 'post:text', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'A Post'),
                )),
            ),
        ),
    ),
);
$inspectorRoleResult = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector() )->inspect($inspectorRoleSource);
$inspectorRoleCandidates = array();
foreach ( ( is_array($inspectorRoleResult['candidates'] ?? null) ? $inspectorRoleResult['candidates'] : array() ) as $rc ) {
    if ( is_array($rc) && isset($rc['id']) ) {
        $inspectorRoleCandidates[(string) $rc['id']] = $rc;
    }
}
// Design-system frames carry role=design_system and no page_type.
$assert(ScenegraphFrameClassifier::ROLE_DESIGN_SYSTEM === ($inspectorRoleCandidates['frame:sg']['role'] ?? null), 'inspector-surfaces-design-system-role');
$assert(! array_key_exists('page_type', $inspectorRoleCandidates['frame:sg'] ?? array()), 'inspector-omits-page-type-on-design-system');
// Real page frames carry role=page and the correct WP-template page_type.
$assert(ScenegraphFrameClassifier::ROLE_PAGE === ($inspectorRoleCandidates['frame:home']['role'] ?? null), 'inspector-surfaces-page-role-on-home');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE === ($inspectorRoleCandidates['frame:home']['page_type'] ?? null), 'inspector-surfaces-front-page-type-on-home');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_SINGLE === ($inspectorRoleCandidates['frame:post']['page_type'] ?? null), 'inspector-surfaces-single-type-on-blog-post');
// Matrix selector must exclude design-system candidates before page selection.
$inspectorRoleCandidateList = array_values($inspectorRoleCandidates);
$matrixRoleIds = matrix_select_frame_ids(array('candidates' => $inspectorRoleCandidateList), 5);
$assert(! in_array('frame:sg', $matrixRoleIds, true), 'matrix-excludes-design-system-from-selection');
$assert(in_array('frame:home', $matrixRoleIds, true), 'matrix-selects-home-page-candidate');
$assert(in_array('frame:post', $matrixRoleIds, true), 'matrix-selects-blog-post-candidate');

// FRAME ROLE CLASSIFICATION (#247): top-level frames are classified into roles
// before any pixels are emitted. A "Style Guide" frame is a design_system frame
// (EXCLUDED from page selection); real pages each carry a WP-template-aligned
// page_type derived from name first, then content shape.
$classificationSource = array(
    'name'  => 'Classification Site',
    'nodes' => array(
        array(
            'id'       => 'canvas:cls',
            'type'     => 'CANVAS',
            'name'     => 'Pages',
            'children' => array(
                // Style guide: name-driven design_system, excluded from pages.
                array(
                    'id'       => 'frame:styleguide',
                    'type'     => 'FRAME',
                    'name'     => 'Style Guide',
                    'width'    => 1440,
                    'height'   => 2000,
                    'children' => array(
                        array('id' => 'sg:text', 'type' => 'TEXT', 'name' => 'Colors', 'characters' => 'Brand Colors'),
                    ),
                ),
                // Homepage → front_page.
                array(
                    'id'       => 'frame:homepage',
                    'type'     => 'FRAME',
                    'name'     => 'Homepage',
                    'width'    => 1440,
                    'height'   => 1600,
                    'children' => array(
                        array('id' => 'hp:text', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome Home'),
                    ),
                ),
                // Blog Post → single.
                array(
                    'id'       => 'frame:blogpost',
                    'type'     => 'FRAME',
                    'name'     => 'Blog Post',
                    'width'    => 1440,
                    'height'   => 2400,
                    'children' => array(
                        array('id' => 'bp:text', 'type' => 'TEXT', 'name' => 'Article Title', 'characters' => 'A Long Read'),
                    ),
                ),
                // Archive → archive.
                array(
                    'id'       => 'frame:archive',
                    'type'     => 'FRAME',
                    'name'     => 'Archive',
                    'width'    => 1440,
                    'height'   => 1800,
                    'children' => array(
                        array('id' => 'ar:text', 'type' => 'TEXT', 'name' => 'Latest Posts', 'characters' => 'Latest Posts'),
                    ),
                ),
                // About → generic page.
                array(
                    'id'       => 'frame:about',
                    'type'     => 'FRAME',
                    'name'     => 'About',
                    'width'    => 1440,
                    'height'   => 1500,
                    'children' => array(
                        array('id' => 'ab:text', 'type' => 'TEXT', 'name' => 'About copy', 'characters' => 'About us'),
                    ),
                ),
            ),
        ),
    ),
);
$classificationPlan = ( new ScenegraphPagePlanner() )->plan($classificationSource, array('include_all_pages' => true));
$classificationPages = is_array($classificationPlan['pages'] ?? null) ? $classificationPlan['pages'] : array();
$classificationByFrame = array();
foreach ( $classificationPages as $classificationPage ) {
    if ( is_array($classificationPage) && isset($classificationPage['frame_id']) ) {
        $classificationByFrame[(string) $classificationPage['frame_id']] = $classificationPage;
    }
}
$classificationFrameIds = array_keys($classificationByFrame);

$assert(! in_array('frame:styleguide', $classificationFrameIds, true), 'classification-style-guide-excluded-from-pages');
$assert(isset($classificationByFrame['frame:homepage']), 'classification-homepage-selected-as-page');
$assert(ScenegraphFrameClassifier::ROLE_PAGE === ($classificationByFrame['frame:homepage']['role'] ?? null), 'classification-homepage-role-page');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE === ($classificationByFrame['frame:homepage']['page_type'] ?? null), 'classification-homepage-front-page');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_SINGLE === ($classificationByFrame['frame:blogpost']['page_type'] ?? null), 'classification-blog-post-single');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_ARCHIVE === ($classificationByFrame['frame:archive']['page_type'] ?? null), 'classification-archive-archive');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_PAGE === ($classificationByFrame['frame:about']['page_type'] ?? null), 'classification-about-generic-page');
$assert(is_array($classificationByFrame['frame:homepage']['classification_signals'] ?? null) && in_array('name:front_page', $classificationByFrame['frame:homepage']['classification_signals'], true), 'classification-homepage-signal-explained');

// COVERAGE: the page plan reports per-role and per-page-type counts, names the
// excluded design-system frame, and the diagnostic explains the exclusion.
$classificationCoverage = is_array($classificationPlan['classification_coverage'] ?? null) ? $classificationPlan['classification_coverage'] : array();
$assert('blocks-engine/figma-transformer/frame-classification/v1' === ($classificationCoverage['schema'] ?? null), 'classification-coverage-schema');
$assert(1 === ($classificationCoverage['roles'][ScenegraphFrameClassifier::ROLE_DESIGN_SYSTEM] ?? null), 'classification-coverage-counts-design-system');
$assert(4 === ($classificationCoverage['roles'][ScenegraphFrameClassifier::ROLE_PAGE] ?? null), 'classification-coverage-counts-pages');
$assert(1 === ($classificationCoverage['page_types'][ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE] ?? null), 'classification-coverage-counts-front-page');
$assert(1 === ($classificationCoverage['page_types'][ScenegraphFrameClassifier::PAGE_TYPE_SINGLE] ?? null), 'classification-coverage-counts-single');
$assert(1 === ($classificationCoverage['page_types'][ScenegraphFrameClassifier::PAGE_TYPE_ARCHIVE] ?? null), 'classification-coverage-counts-archive');
$assert(1 === ($classificationCoverage['page_types'][ScenegraphFrameClassifier::PAGE_TYPE_PAGE] ?? null), 'classification-coverage-counts-page');
$assert(in_array('frame:styleguide', $classificationCoverage['excluded_design_system_frame_ids'] ?? array(), true), 'classification-coverage-names-excluded-frame');
$classificationDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    is_array($classificationPlan['diagnostics'] ?? null) ? $classificationPlan['diagnostics'] : array()
);
$assert(in_array('figma_frame_classification_coverage', $classificationDiagnosticCodes, true), 'classification-coverage-diagnostic-emitted');

// CONTENT-SHAPE: classification is generic — a design-system frame with a
// GENERIC name still classifies as design_system from a swatch grid, and an
// unnamed list-of-cards page classifies as archive from its repeating structure.
$contentShapeSwatch = array();
for ( $swatchIndex = 0; $swatchIndex < 8; ++$swatchIndex ) {
    $contentShapeSwatch[] = array('id' => 'swatch:' . $swatchIndex, 'type' => 'RECTANGLE', 'name' => 'Swatch ' . $swatchIndex, 'width' => 80, 'height' => 80);
}
$contentShapeCards = array();
for ( $cardIndex = 0; $cardIndex < 4; ++$cardIndex ) {
    $contentShapeCards[] = array(
        'id'       => 'card:' . $cardIndex,
        'type'     => 'FRAME',
        'name'     => 'Card ' . $cardIndex,
        'width'    => 360,
        'height'   => 420,
        'children' => array(
            array('id' => 'card-img:' . $cardIndex, 'type' => 'RECTANGLE', 'name' => 'Thumb', 'width' => 360, 'height' => 200, 'fills' => array(array('type' => 'IMAGE', 'imageRef' => 'hash:' . $cardIndex))),
            array('id' => 'card-title:' . $cardIndex, 'type' => 'TEXT', 'name' => 'Card Title', 'characters' => 'Post ' . $cardIndex),
        ),
    );
}
$contentShapeSource = array(
    'name'  => 'Content Shape Site',
    'nodes' => array(
        array(
            'id'       => 'canvas:shape',
            'type'     => 'CANVAS',
            'name'     => 'Pages',
            'children' => array(
                array(
                    'id'       => 'frame:palette',
                    'type'     => 'FRAME',
                    'name'     => 'Foundations',
                    'width'    => 1440,
                    'height'   => 1600,
                    'children' => $contentShapeSwatch,
                ),
                array(
                    'id'       => 'frame:list',
                    'type'     => 'FRAME',
                    'name'     => 'Updates',
                    'width'    => 1440,
                    'height'   => 1800,
                    'children' => $contentShapeCards,
                ),
            ),
        ),
    ),
);
$contentShapePlan = ( new ScenegraphPagePlanner() )->plan($contentShapeSource, array('include_all_pages' => true));
$contentShapePages = is_array($contentShapePlan['pages'] ?? null) ? $contentShapePlan['pages'] : array();
$contentShapeByFrame = array();
foreach ( $contentShapePages as $contentShapePage ) {
    if ( is_array($contentShapePage) && isset($contentShapePage['frame_id']) ) {
        $contentShapeByFrame[(string) $contentShapePage['frame_id']] = $contentShapePage;
    }
}
$assert(! isset($contentShapeByFrame['frame:palette']), 'classification-content-swatch-grid-excluded');
$contentShapeCoverage = is_array($contentShapePlan['classification_coverage'] ?? null) ? $contentShapePlan['classification_coverage'] : array();
$assert(in_array('frame:palette', $contentShapeCoverage['excluded_design_system_frame_ids'] ?? array(), true), 'classification-content-swatch-grid-design-system');
$assert(ScenegraphFrameClassifier::PAGE_TYPE_ARCHIVE === ($contentShapeByFrame['frame:list']['page_type'] ?? null), 'classification-content-card-list-archive');
$assert(in_array('content:card_list', $contentShapeByFrame['frame:list']['classification_signals'] ?? array(), true), 'classification-content-card-list-signal');

// MULTI-PAGE SELECTION (#280/#242 FSE Pilot acceptance): `--multi-page` (no
// `frame_ids`) selects the TOP-LEVEL page frames on a canvas, groups each
// page's desktop+mobile variants into ONE responsive page, excludes the
// design-system frame, and ignores both nested annotation frames and oversized
// decorative boards. Backward compat: the SAME scenegraph with neither
// `multi_page` nor `frame_ids` still slices to a single page.
$multiPageSource = array(
    'name'  => 'Multi Page Selection Site',
    'nodes' => array(
        array(
            'id'       => 'canvas:mockups',
            'type'     => 'CANVAS',
            'name'     => 'Mockups (dev handoff)',
            'children' => array(
                // Home: a desktop + mobile pair → one responsive page. The
                // desktop frame also carries a NESTED annotation frame that must
                // never be selected as a page of its own.
                array(
                    'id'       => 'frame:home-desktop',
                    'type'     => 'FRAME',
                    'name'     => 'Home Page – Desktop',
                    'width'    => 1440,
                    'height'   => 3200,
                    'children' => array(
                        array('id' => 'home:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                        array(
                            'id'       => 'frame:home-annotation',
                            'type'     => 'FRAME',
                            'name'     => 'Buttons',
                            'width'    => 240,
                            'height'   => 120,
                            'children' => array(
                                array('id' => 'home:btn', 'type' => 'TEXT', 'name' => 'Button label', 'characters' => 'Click'),
                            ),
                        ),
                    ),
                ),
                array(
                    'id'       => 'frame:home-mobile',
                    'type'     => 'FRAME',
                    'name'     => 'Home Page – Mobile',
                    'width'    => 390,
                    'height'   => 5200,
                    'children' => array(
                        array('id' => 'home-m:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                    ),
                ),
                // Contact: a second desktop + mobile pair → one responsive page.
                array(
                    'id'       => 'frame:contact-desktop',
                    'type'     => 'FRAME',
                    'name'     => 'Contact – Desktop',
                    'width'    => 1440,
                    'height'   => 2000,
                    'children' => array(
                        array('id' => 'contact:title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Contact us'),
                    ),
                ),
                array(
                    'id'       => 'frame:contact-mobile',
                    'type'     => 'FRAME',
                    'name'     => 'Contact – Mobile',
                    'width'    => 390,
                    'height'   => 2600,
                    'children' => array(
                        array('id' => 'contact-m:title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Contact us'),
                    ),
                ),
                // Design-system frame: excluded from page selection by role.
                array(
                    'id'       => 'frame:styleguide',
                    'type'     => 'FRAME',
                    'name'     => 'Style Guide',
                    'width'    => 1440,
                    'height'   => 2000,
                    'children' => array(
                        array('id' => 'sg:swatch', 'type' => 'TEXT', 'name' => 'Colors', 'characters' => 'Brand Colors'),
                    ),
                ),
                // Oversized decorative divider board: off the page-width band, so
                // excluded as a non-page even though it is top-level on the canvas.
                array(
                    'id'       => 'frame:title-card',
                    'type'     => 'FRAME',
                    'name'     => 'Title Card',
                    'width'    => 2238,
                    'height'   => 291,
                    'children' => array(
                        array('id' => 'tc:label', 'type' => 'TEXT', 'name' => 'Section label', 'characters' => 'Mockups'),
                    ),
                ),
            ),
        ),
    ),
);
$multiPagePlan = ( new ScenegraphPagePlanner() )->plan($multiPageSource, array('multi_page' => true, 'max_pages' => 20));
$multiPageByFrame = array();
foreach ( $multiPagePlan['pages'] ?? array() as $multiPagePage ) {
    if ( is_array($multiPagePage) && isset($multiPagePage['frame_id']) ) {
        $multiPageByFrame[(string) $multiPagePage['frame_id']] = $multiPagePage;
    }
}
$multiPageFrameIds = array_keys($multiPageByFrame);
// (a) One responsive page per distinct page name (Home, Contact).
$assert(2 === ($multiPagePlan['page_count'] ?? null), 'multi-page-selects-one-page-per-distinct-name');
$assert(isset($multiPageByFrame['frame:home-desktop']), 'multi-page-home-primary-is-desktop');
$assert(true === ($multiPageByFrame['frame:home-desktop']['responsive'] ?? null)
    && 2 === ($multiPageByFrame['frame:home-desktop']['breakpoint_count'] ?? null), 'multi-page-home-groups-desktop-mobile');
$assert(array('frame:home-desktop', 'frame:home-mobile')
    === array_map(static fn (array $variant): string => (string) ($variant['frame_id'] ?? ''), $multiPageByFrame['frame:home-desktop']['variants'] ?? array()), 'multi-page-home-variants-widest-first');
$assert(isset($multiPageByFrame['frame:contact-desktop'])
    && true === ($multiPageByFrame['frame:contact-desktop']['responsive'] ?? null), 'multi-page-contact-groups-desktop-mobile');
// The `front_page`-classified page owns the index.html entrypoint, not whichever
// page merely ranked first.
$assert(ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE === ($multiPageByFrame['frame:home-desktop']['page_type'] ?? null), 'multi-page-home-classified-front-page');
$assert(true === ($multiPageByFrame['frame:home-desktop']['entrypoint'] ?? null)
    && 'index.html' === ($multiPageByFrame['frame:home-desktop']['path'] ?? null), 'multi-page-front-page-is-entrypoint');
$assert(false === ($multiPageByFrame['frame:contact-desktop']['entrypoint'] ?? null), 'multi-page-non-front-page-not-entrypoint');
// Responsive page slugs/paths strip the breakpoint token.
$assert('home-page' === ($multiPageByFrame['frame:home-desktop']['slug'] ?? null), 'multi-page-home-slug-normalized');
$assert('contact' === ($multiPageByFrame['frame:contact-desktop']['slug'] ?? null)
    && 'contact.html' === ($multiPageByFrame['frame:contact-desktop']['path'] ?? null), 'multi-page-contact-slug-and-path-normalized');
// (b) Nested annotation frame is NOT a page.
$assert(! in_array('frame:home-annotation', $multiPageFrameIds, true), 'multi-page-nested-frame-not-a-page');
// (c) Design-system frame is excluded from pages (and reported as such).
$assert(! in_array('frame:styleguide', $multiPageFrameIds, true), 'multi-page-design-system-excluded');
$assert(in_array('frame:styleguide', $multiPagePlan['classification_coverage']['excluded_design_system_frame_ids'] ?? array(), true), 'multi-page-design-system-reported-excluded');
// Oversized decorative board is excluded as a non-page.
$assert(! in_array('frame:title-card', $multiPageFrameIds, true), 'multi-page-oversized-divider-not-a-page');
// (d) Backward compat: no multi_page / no frame_ids → single page.
$singlePagePlan = ( new ScenegraphPagePlanner() )->plan($multiPageSource);
$assert(1 === ($singlePagePlan['page_count'] ?? null), 'multi-page-backward-compat-single-page-default');

// ORPHAN MOBILE MENU EXCLUSION: when a .fig file has responsive desktop+mobile
// page pairs AND contains an extra lone mobile-width frame whose name matches a
// nav/menu component pattern and whose page_type is unknown, the orphan frame
// must be excluded from page selection. The 5 real pages must be completely
// unaffected. This is the regression that the FSE Pilot "Mobile Menu" frame
// triggered — a component demo of the open-menu state, not a real page.
$orphanMenuSource = array(
    'name'  => 'FSE Pilot Orphan Menu Demo',
    'nodes' => array(
        array(
            'id'       => 'canvas:fse',
            'type'     => 'CANVAS',
            'name'     => 'FSE Pilot',
            'children' => array(
                // Home Page: responsive desktop + mobile pair.
                array(
                    'id'       => 'frame:home-d',
                    'type'     => 'FRAME',
                    'name'     => 'Home Page – Desktop',
                    'width'    => 1440,
                    'height'   => 3200,
                    'children' => array(
                        array('id' => 'home-d:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                    ),
                ),
                array(
                    'id'       => 'frame:home-m',
                    'type'     => 'FRAME',
                    'name'     => 'Home Page – Mobile',
                    'width'    => 390,
                    'height'   => 5200,
                    'children' => array(
                        array('id' => 'home-m:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                    ),
                ),
                // Blog Post: responsive pair.
                array(
                    'id'       => 'frame:blog-d',
                    'type'     => 'FRAME',
                    'name'     => 'Blog Post – Desktop',
                    'width'    => 1440,
                    'height'   => 4000,
                    'children' => array(
                        array('id' => 'blog-d:title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Post Title'),
                    ),
                ),
                array(
                    'id'       => 'frame:blog-m',
                    'type'     => 'FRAME',
                    'name'     => 'Blog Post – Mobile',
                    'width'    => 390,
                    'height'   => 6000,
                    'children' => array(
                        array('id' => 'blog-m:title', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Post Title'),
                    ),
                ),
                // Orphan mobile menu frame: a component demo of the open-menu
                // state — lone mobile-width, no desktop sibling, unknown page_type.
                // Matches the FSE Pilot node 4210:11834 "Mobile Menu" pattern.
                array(
                    'id'       => 'frame:mobile-menu',
                    'type'     => 'FRAME',
                    'name'     => 'Mobile Menu',
                    'width'    => 390,
                    'height'   => 844,
                    'children' => array(
                        array('id' => 'mm:link1', 'type' => 'TEXT', 'name' => 'Nav link', 'characters' => 'Home'),
                        array('id' => 'mm:link2', 'type' => 'TEXT', 'name' => 'Nav link', 'characters' => 'About'),
                    ),
                ),
            ),
        ),
    ),
);
$orphanMenuPlan = ( new ScenegraphPagePlanner() )->plan($orphanMenuSource, array('multi_page' => true, 'max_pages' => 20));
$orphanMenuByFrame = array();
foreach ( $orphanMenuPlan['pages'] ?? array() as $orphanMenuPage ) {
    if ( is_array($orphanMenuPage) && isset($orphanMenuPage['frame_id']) ) {
        $orphanMenuByFrame[(string) $orphanMenuPage['frame_id']] = $orphanMenuPage;
    }
}
$orphanMenuFrameIds = array_keys($orphanMenuByFrame);
// Real pages: the two responsive pairs must be selected.
$assert(2 === ($orphanMenuPlan['page_count'] ?? null), 'orphan-menu-two-real-pages-selected');
$assert(isset($orphanMenuByFrame['frame:home-d']), 'orphan-menu-home-desktop-selected');
$assert(true === ($orphanMenuByFrame['frame:home-d']['responsive'] ?? null), 'orphan-menu-home-is-responsive');
$assert(isset($orphanMenuByFrame['frame:blog-d']), 'orphan-menu-blog-desktop-selected');
$assert(true === ($orphanMenuByFrame['frame:blog-d']['responsive'] ?? null), 'orphan-menu-blog-is-responsive');
// Orphan mobile menu frame must be excluded from page selection.
$assert(! in_array('frame:mobile-menu', $orphanMenuFrameIds, true), 'orphan-menu-mobile-menu-excluded');

// REGRESSION GUARD — mobile-only site: when the file has NO responsive pairs
// (all frames are mobile-width with no desktop counterpart), a mobile-width
// frame with a menu-like name must NOT be excluded, because the file is a
// genuine mobile-only design, not a file with orphan component demos.
$mobileOnlySource = array(
    'name'  => 'Mobile Only Site',
    'nodes' => array(
        array(
            'id'       => 'canvas:mob',
            'type'     => 'CANVAS',
            'name'     => 'Mobile Pages',
            'children' => array(
                // A genuine mobile-only home page.
                array(
                    'id'       => 'frame:mob-home',
                    'type'     => 'FRAME',
                    'name'     => 'Home',
                    'width'    => 390,
                    'height'   => 3200,
                    'children' => array(
                        array('id' => 'mob-home:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                    ),
                ),
                // A genuine mobile-only page whose name contains "Mobile Menu" —
                // must NOT be filtered because the file has no responsive pairs.
                array(
                    'id'       => 'frame:mob-menu',
                    'type'     => 'FRAME',
                    'name'     => 'Mobile Menu',
                    'width'    => 390,
                    'height'   => 844,
                    'children' => array(
                        array('id' => 'mob-menu:link', 'type' => 'TEXT', 'name' => 'Link', 'characters' => 'Home'),
                    ),
                ),
            ),
        ),
    ),
);
$mobileOnlyPlan = ( new ScenegraphPagePlanner() )->plan($mobileOnlySource, array('multi_page' => true, 'max_pages' => 20));
$mobileOnlyByFrame = array();
foreach ( $mobileOnlyPlan['pages'] ?? array() as $mobileOnlyPage ) {
    if ( is_array($mobileOnlyPage) && isset($mobileOnlyPage['frame_id']) ) {
        $mobileOnlyByFrame[(string) $mobileOnlyPage['frame_id']] = $mobileOnlyPage;
    }
}
// Both frames must survive in a mobile-only file (no responsive pairs present).
$assert(isset($mobileOnlyByFrame['frame:mob-home']), 'mobile-only-home-selected');
$assert(isset($mobileOnlyByFrame['frame:mob-menu']), 'mobile-only-menu-page-not-excluded');
$assert(2 === ($mobileOnlyPlan['page_count'] ?? null), 'mobile-only-two-pages-selected');

// Semantic HTML5 elements: a generically-named page structure maps to landmarks
// (header/nav/main/section/footer), a font-size hierarchy maps to h1/h2, repeated
// sibling cards map to <ul>/<li>, and a button-like control maps to <button>.
$semanticElementsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Semantic Elements Fixture',
    'nodes' => array(
        array(
            'id'       => 'page:root',
            'type'     => 'FRAME',
            'name'     => 'Page',
            'x'        => 0,
            'y'        => 0,
            'width'    => 1200,
            'height'   => 2000,
            'children' => array(
                // Top region: logo + link cluster -> <header> containing <nav>.
                array(
                    'id'       => 'region:top',
                    'type'     => 'FRAME',
                    'name'     => 'Top Bar',
                    'x'        => 0,
                    'y'        => 0,
                    'width'    => 1200,
                    'height'   => 80,
                    'children' => array(
                        array('id' => 'top:logo', 'type' => 'FRAME', 'name' => 'Site Logo', 'x' => 0, 'y' => 0, 'width' => 120, 'height' => 40),
                        array(
                            'id'       => 'top:menu',
                            'type'     => 'FRAME',
                            'name'     => 'Primary Links',
                            'x'        => 200,
                            'y'        => 0,
                            'width'    => 400,
                            'height'   => 40,
                            'children' => array(
                                array('id' => 'top:link-1', 'type' => 'TEXT', 'name' => 'Link One', 'characters' => 'Home', 'fontSize' => 16, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/')),
                                array('id' => 'top:link-2', 'type' => 'TEXT', 'name' => 'Link Two', 'characters' => 'About', 'fontSize' => 16, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/about')),
                            ),
                        ),
                    ),
                ),
                // Primary content: headings by size + a repeated card list + a CTA.
                array(
                    'id'       => 'region:body',
                    'type'     => 'FRAME',
                    'name'     => 'Content',
                    'x'        => 0,
                    'y'        => 100,
                    'width'    => 1200,
                    'height'   => 1600,
                    'children' => array(
                        array('id' => 'body:h1', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome', 'fontSize' => 48, 'fontWeight' => 700),
                        array('id' => 'body:h2', 'type' => 'TEXT', 'name' => 'Subhead', 'characters' => 'Our Services', 'fontSize' => 28, 'fontWeight' => 600),
                        array('id' => 'body:p1', 'type' => 'TEXT', 'name' => 'Intro', 'characters' => 'We build delightful things for everyone.', 'fontSize' => 16),
                        array('id' => 'body:p2', 'type' => 'TEXT', 'name' => 'More', 'characters' => 'Read on to learn what we offer today.', 'fontSize' => 16),
                        array(
                            'id'       => 'body:cards',
                            'type'     => 'FRAME',
                            'name'     => 'Feature Cards',
                            'x'        => 0,
                            'y'        => 200,
                            'width'    => 1200,
                            'height'   => 300,
                            'children' => array(
                                array('id' => 'card:1', 'type' => 'FRAME', 'name' => 'Card One', 'x' => 0, 'y' => 0, 'width' => 380, 'height' => 200, 'children' => array(
                                    array('id' => 'card:1-text', 'type' => 'TEXT', 'name' => 'Card One Body', 'characters' => 'First feature description.', 'fontSize' => 16),
                                )),
                                array('id' => 'card:2', 'type' => 'FRAME', 'name' => 'Card Two', 'x' => 400, 'y' => 0, 'width' => 380, 'height' => 200, 'children' => array(
                                    array('id' => 'card:2-text', 'type' => 'TEXT', 'name' => 'Card Two Body', 'characters' => 'Second feature description.', 'fontSize' => 16),
                                )),
                                array('id' => 'card:3', 'type' => 'FRAME', 'name' => 'Card Three', 'x' => 800, 'y' => 0, 'width' => 380, 'height' => 200, 'children' => array(
                                    array('id' => 'card:3-text', 'type' => 'TEXT', 'name' => 'Card Three Body', 'characters' => 'Third feature description.', 'fontSize' => 16),
                                )),
                            ),
                        ),
                        array(
                            'id'         => 'body:cta',
                            'type'       => 'FRAME',
                            'name'       => 'Get Started',
                            'x'          => 0,
                            'y'          => 600,
                            'width'      => 180,
                            'height'     => 48,
                            'cornerRadius' => 8,
                            'fill'       => array('r' => 0.1, 'g' => 0.4, 'b' => 0.9),
                            'children'   => array(
                                array('id' => 'cta:label', 'type' => 'TEXT', 'name' => 'CTA Label', 'characters' => 'Get Started', 'fontSize' => 16),
                            ),
                        ),
                    ),
                ),
                // Bottom region: links + legal text -> <footer>.
                array(
                    'id'       => 'region:bottom',
                    'type'     => 'FRAME',
                    'name'     => 'Bottom Bar',
                    'x'        => 0,
                    'y'        => 1800,
                    'width'    => 1200,
                    'height'   => 200,
                    'children' => array(
                        array('id' => 'bottom:link', 'type' => 'TEXT', 'name' => 'Privacy', 'characters' => 'Privacy', 'fontSize' => 14, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/privacy')),
                        array('id' => 'bottom:legal', 'type' => 'TEXT', 'name' => 'Legal', 'characters' => '© 2026 Example, Inc. All rights reserved.', 'fontSize' => 14),
                    ),
                ),
            ),
        ),
    ),
));
$semanticHtml = $fileContent($semanticElementsResult, 'index.html');
$assert('success' === ($semanticElementsResult['status'] ?? null), 'semantic-elements-transform-success');
// The page shell already provides <main>; assert it wraps the rendered body.
$assert(str_contains($semanticHtml, '<main class="figma-root" data-figma-root="true">'), 'semantic-main-landmark');
$assert(str_contains($semanticHtml, '<header class="figma-node-region-top-top-bar"'), 'semantic-top-region-emits-header');
$assert(str_contains($semanticHtml, '<nav class="figma-node-top-menu-primary-links"'), 'semantic-link-cluster-emits-nav');
$assert(str_contains($semanticHtml, '<footer class="figma-node-region-bottom-bottom-bar"'), 'semantic-bottom-region-emits-footer');
$assert(str_contains($semanticHtml, '<h1 class="figma-node-body-h1-hero"'), 'semantic-largest-text-emits-h1');
$assert(str_contains($semanticHtml, '<h2 class="figma-node-body-h2-subhead"'), 'semantic-second-text-emits-h2');
$assert(str_contains($semanticHtml, '<p class="figma-node-body-p1-intro'), 'semantic-body-copy-emits-paragraph');
$assert(str_contains($semanticHtml, '<ul class="figma-node-body-cards-feature-cards"'), 'semantic-repeated-items-emit-list');
$assert(str_contains($semanticHtml, '<li class="figma-node-card-1-card-one"'), 'semantic-repeated-item-emits-list-item');
$assert(str_contains($semanticHtml, '<button class="figma-node-body-cta-get-started"'), 'semantic-button-like-node-emits-button');
$assert(! str_contains($semanticHtml, '<div class="figma-node-region-top-top-bar"'), 'semantic-header-not-generic-div');
// The middle content band (not a header/nav/footer landmark) is the genuine
// top-level region and reads as the page's single <section>; the deeply nested
// card/cta frames inside it do NOT each become their own <section>.
$assert(str_contains($semanticHtml, '<section class="figma-node-region-body-content"'), 'semantic-top-level-band-emits-section');
$assert(1 === substr_count($semanticHtml, '<section'), 'semantic-page-has-single-section');

// Section refinement (#247 / #288 follow-up): a page with a few genuine
// top-level bands wrapped around many deeply-nested containers (rows, columns,
// cards, wrappers) emits <section> only for the top-level bands. Nested
// structural frames stay <div>, so the page has single-digit sections rather
// than one per container (the real FSE fixture over-emitted 347 <section>s).
$buildNestedContainer = static function (string $idPrefix, int $depth) use (&$buildNestedContainer): array {
    $node = array(
        'id'     => $idPrefix,
        'type'   => 'FRAME',
        'name'   => 'Wrapper ' . $idPrefix,
        'x'      => 0,
        'y'      => 0,
        'width'  => 1000,
        'height' => 400,
    );
    if ( $depth > 0 ) {
        $node['children'] = array(
            $buildNestedContainer($idPrefix . '-row', $depth - 1),
            $buildNestedContainer($idPrefix . '-col', $depth - 1),
        );
    } else {
        $node['children'] = array(
            array('id' => $idPrefix . '-t', 'type' => 'TEXT', 'name' => 'Copy ' . $idPrefix, 'characters' => 'Some descriptive body copy for the band.', 'fontSize' => 16),
        );
    }

    return $node;
};
// Distinct top-level bands (different heading copy, different child shape and
// nesting depth) so they read as separate content regions, not as repeated
// list items. Each carries a deep wrapper tree of nested frames.
$makeBand = static function (string $id, string $name, float $y, int $wrapDepth, int $headingSize, string $body, float $height = 800.0) use ($buildNestedContainer): array {
    return array(
        'id'       => $id,
        'type'     => 'FRAME',
        'name'     => $name,
        'x'        => 0,
        'y'        => $y,
        'width'    => 1200,
        'height'   => $height,
        'children' => array(
            // A heading run plus a deeply-nested wrapper tree per band. Each
            // wrapper subtree contains many nested frames that, under the old
            // "every FRAME is a <section>" rule, each became a <section>.
            array('id' => $id . '-head', 'type' => 'TEXT', 'name' => $name . ' Heading', 'characters' => $name, 'fontSize' => $headingSize, 'fontWeight' => 700),
            array('id' => $id . '-sub', 'type' => 'TEXT', 'name' => $name . ' Sub', 'characters' => $body, 'fontSize' => 18),
            $buildNestedContainer($id . '-wrap', $wrapDepth),
        ),
    );
};
$nestingResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Section Refinement Fixture',
    'nodes' => array(
        array(
            'id'       => 'sr:root',
            'type'     => 'FRAME',
            'name'     => 'Page Root',
            'x'        => 0,
            'y'        => 0,
            'width'    => 1200,
            'height'   => 4000,
            'children' => array(
                // Distinct heights so the bands read as separate regions, not a
                // repeated card list, while each nests a deep wrapper subtree.
                $makeBand('sr:band-1', 'Overview Band', 0.0, 4, 40, 'A broad introduction to what this page is about.', 600.0),
                $makeBand('sr:band-2', 'Features Band', 700.0, 3, 34, 'The key capabilities, grouped for scanning.', 1000.0),
                $makeBand('sr:band-3', 'Pricing Band', 1800.0, 2, 30, 'Plans and prices laid out side by side.', 1400.0),
            ),
        ),
    ),
));
$nestingHtml = $fileContent($nestingResult, 'index.html');
$assert('success' === ($nestingResult['status'] ?? null), 'section-refinement-transform-success');
$nestingSectionCount = substr_count($nestingHtml, '<section');
// Exactly the three top-level bands are sections; the dozens of nested wrapper
// frames are not. A small, hand-authored-looking count, not hundreds.
$assert(3 === $nestingSectionCount, 'section-refinement-only-top-level-bands-are-sections');
$assert(str_contains($nestingHtml, '<section class="figma-node-sr-band-1-overview-band"'), 'section-refinement-band-1-is-section');
$assert(str_contains($nestingHtml, '<section class="figma-node-sr-band-3-pricing-band"'), 'section-refinement-band-3-is-section');
// Nested structural wrappers (depth >= 2) default to <div>, never <section>.
$assert(str_contains($nestingHtml, '<div class="figma-node-sr-band-1-wrap-wrapper-sr-band-1-wrap'), 'section-refinement-nested-wrapper-is-div');
$assert(! str_contains($nestingHtml, '<section class="figma-node-sr-band-1-wrap'), 'section-refinement-no-nested-section');
// A page whose top-level bands are emitted as root-level nodes (no single page
// wrapper) still gets each band as a <section> via the depth-0 path.
$rootLevelBandsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Root Level Bands Fixture',
    'nodes' => array(
        $makeBand('rl:band-1', 'Intro Band', 0.0, 3, 40, 'The opening statement for the whole page.', 700.0),
        $makeBand('rl:band-2', 'Detail Band', 900.0, 2, 30, 'Deeper detail in its own distinct region.', 1200.0),
    ),
));
$rootLevelHtml = $fileContent($rootLevelBandsResult, 'index.html');
$assert(2 === substr_count($rootLevelHtml, '<section'), 'section-refinement-root-level-bands-are-sections');

blocks_engine_figma_transformer_run_fixture_matrix_contract($assert);
blocks_engine_figma_transformer_run_node_trace_contract($assert);

// Authored CSS classes: repeated per-node styles collapse into shared, readably
// named classes (like a hand-authored site reuses `.card`), names derive from
// node names/roles, and class names + stylesheet ordering stay deterministic.
$authoredCssScenegraph = array(
    'name'  => 'Authored CSS Fixture',
    'nodes' => array(
        array(
            'id'         => 'authored:hero',
            'type'       => 'FRAME',
            'name'       => 'Hero Section',
            'width'      => 1200,
            'height'     => 400,
            'layoutMode' => 'VERTICAL',
            'children'   => array(
                array(
                    'id'              => 'authored:card-1',
                    'type'            => 'FRAME',
                    'name'            => 'Primary Card',
                    'width'           => 300,
                    'height'          => 200,
                    'backgroundColor' => '#ffffff',
                    'cornerRadius'    => 8,
                ),
                array(
                    'id'              => 'authored:card-2',
                    'type'            => 'FRAME',
                    'name'            => 'Secondary Card',
                    'width'           => 300,
                    'height'          => 200,
                    'backgroundColor' => '#ffffff',
                    'cornerRadius'    => 8,
                ),
            ),
        ),
        array(
            'id'              => 'authored:hero-twin',
            'type'            => 'FRAME',
            'name'            => 'Hero Section',
            'width'           => 1200,
            'height'          => 400,
            'layoutMode'      => 'VERTICAL',
        ),
    ),
);
$authoredCssResult = blocks_engine_figma_transformer_transform_scenegraph($authoredCssScenegraph);
$authoredCss = $fileContent($authoredCssResult, 'style.css');
$authoredHtml = $fileContent($authoredCssResult, 'index.html');

// Two cards with identical styles share ONE emitted class, not duplicated rules.
$assert(1 === substr_count($authoredCss, 'background:#ffffff;border-radius:8px'), 'authored-css-shared-card-style-emitted-once');
$assert(str_contains($authoredHtml, 'class="figma-node-authored-card-1-primary-card primary-card"'), 'authored-css-first-card-references-shared-class');
$assert(str_contains($authoredHtml, 'class="figma-node-authored-card-2-secondary-card primary-card"'), 'authored-css-second-card-references-shared-class');
$assert(str_contains($authoredCss, '.primary-card{'), 'authored-css-shared-class-readably-named');

// A node named "Hero Section" yields a readable `hero-section` class (no node id).
$assert(str_contains($authoredCss, '.hero-section{'), 'authored-css-hero-section-readable-class');
$assert(! preg_match('/\.hero-section[^{]*authored/', $authoredCss), 'authored-css-shared-name-has-no-node-id');
$assert(str_contains($authoredHtml, 'class="figma-node-authored-hero-hero-section hero-section"'), 'authored-css-hero-references-shared-class');

// No inline style attributes leak onto emitted node elements.
$assert(! str_contains($authoredHtml, 'data-figma-node-id="authored:hero" style='), 'authored-css-prefers-classes-over-inline-style');

// Determinism: two runs produce byte-identical stylesheets and HTML.
$authoredCssRerunResult = blocks_engine_figma_transformer_transform_scenegraph($authoredCssScenegraph);
$assert($authoredCss === $fileContent($authoredCssRerunResult, 'style.css'), 'authored-css-stylesheet-deterministic');
$assert($authoredHtml === $fileContent($authoredCssRerunResult, 'index.html'), 'authored-css-html-deterministic');

// Design system: a "Style Guide" frame is the SOURCE of the design system. Its
// color swatches become `:root` custom properties, its distinct text styles
// become a reusable type scale, consistent spacing becomes spacing tokens, and a
// coverage diagnostic counts what was extracted. The frame feeds global CSS — it
// is not rendered as the design itself.
$designSystemScenegraph = array(
    'name'  => 'Design System Fixture',
    'nodes' => array(
        array(
            'id'         => 'ds:guide',
            'type'       => 'FRAME',
            'name'       => 'Style Guide',
            'width'      => 1200,
            'height'     => 800,
            'layoutMode' => 'VERTICAL',
            'itemSpacing' => 24,
            'paddingTop'  => 32,
            'paddingLeft' => 32,
            'children'   => array(
                array(
                    'id'         => 'ds:swatch-primary',
                    'type'       => 'RECTANGLE',
                    'name'       => 'Primary',
                    'width'      => 80,
                    'height'     => 80,
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.8))),
                ),
                array(
                    'id'         => 'ds:swatch-accent',
                    'type'       => 'RECTANGLE',
                    'name'       => 'Accent',
                    'width'      => 80,
                    'height'     => 80,
                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.9, 'g' => 0.3, 'b' => 0.2))),
                ),
                array(
                    'id'       => 'ds:type-heading',
                    'type'     => 'TEXT',
                    'name'     => 'Heading',
                    'text'     => 'Heading specimen',
                    'fontSize' => 48,
                    'style'    => array('fontFamily' => 'Inter', 'fontSize' => 48, 'fontWeight' => 700, 'lineHeightPx' => 56),
                ),
                array(
                    'id'       => 'ds:type-body',
                    'type'     => 'TEXT',
                    'name'     => 'Body',
                    'text'     => 'Body specimen',
                    'fontSize' => 16,
                    'style'    => array('fontFamily' => 'Inter', 'fontSize' => 16, 'fontWeight' => 400, 'lineHeightPx' => 24),
                ),
            ),
        ),
    ),
);
$designSystemResult = blocks_engine_figma_transformer_transform_scenegraph($designSystemScenegraph);
$designSystemCss = $fileContent($designSystemResult, 'style.css');

// Colors become `:root` custom properties, named from swatch labels.
$assert(str_contains($designSystemCss, ':root{'), 'design-system-root-block-emitted');
$assert(str_contains($designSystemCss, '--color-primary:#3366cc'), 'design-system-primary-color-token');
$assert(str_contains($designSystemCss, '--color-accent:#e64d33'), 'design-system-accent-color-token');

// Typography becomes custom properties plus a reusable type-scale class set.
$assert(str_contains($designSystemCss, '--font-size-heading-1:48px'), 'design-system-heading-font-size-token');
$assert(str_contains($designSystemCss, '--font-size-body:16px'), 'design-system-body-font-size-token');
$assert(str_contains($designSystemCss, '.type-heading-1{'), 'design-system-heading-type-class');
$assert(str_contains($designSystemCss, '.type-body{'), 'design-system-body-type-class');
$assert(str_contains($designSystemCss, 'font-size:var(--font-size-heading-1)'), 'design-system-type-class-references-token');
$assert(str_contains($designSystemCss, 'line-height:56px'), 'design-system-type-class-carries-line-height');

// Consistent spacing becomes a spacing token.
$assert(str_contains($designSystemCss, '--space-1:32px'), 'design-system-spacing-token');

// The `:root` block leads the stylesheet, before the per-node page rules.
$rootPos = strpos($designSystemCss, ':root{');
$nodePos = strpos($designSystemCss, '.figma-node-');
$assert(false !== $rootPos && false !== $nodePos && $rootPos < $nodePos, 'design-system-root-precedes-page-rules');

// The coverage diagnostic counts the extracted tokens.
$designSystemReport = is_array($designSystemResult['source_reports']['figma']['html']['design_system'] ?? null)
    ? $designSystemResult['source_reports']['figma']['html']['design_system']
    : array();
$designSystemCoverage = is_array($designSystemReport['coverage'] ?? null) ? $designSystemReport['coverage'] : array();
$assert(1 === ($designSystemCoverage['frame_count'] ?? 0), 'design-system-coverage-frame-count');
$assert(2 === ($designSystemCoverage['color_tokens'] ?? 0), 'design-system-coverage-color-count');
$assert(2 === ($designSystemCoverage['type_tokens'] ?? 0), 'design-system-coverage-type-count');
$assert(($designSystemCoverage['spacing_tokens'] ?? 0) >= 1, 'design-system-coverage-spacing-count');

// A matching coverage diagnostic is surfaced for operators.
$designSystemDiagnostic = null;
foreach ( (is_array($designSystemResult['diagnostics'] ?? null) ? $designSystemResult['diagnostics'] : array()) as $diagnostic ) {
    if ( is_array($diagnostic) && 'design_system_extracted' === ($diagnostic['code'] ?? '') ) {
        $designSystemDiagnostic = $diagnostic;
        break;
    }
}
$assert(null !== $designSystemDiagnostic, 'design-system-coverage-diagnostic-emitted');
$assert(null !== $designSystemDiagnostic && 2 === ($designSystemDiagnostic['color_tokens'] ?? 0), 'design-system-diagnostic-color-count');

// A plain site without a style-guide frame extracts no design system, so the
// `:root` token block never pollutes ordinary pages.
$plainSiteScenegraph = array(
    'name'  => 'Plain Site Fixture',
    'nodes' => array(
        array(
            'id'         => 'plain:hero',
            'type'       => 'FRAME',
            'name'       => 'Hero Section',
            'width'      => 1200,
            'height'     => 400,
            'layoutMode' => 'VERTICAL',
            'children'   => array(
                array('id' => 'plain:title', 'type' => 'TEXT', 'name' => 'Title', 'text' => 'Welcome', 'fontSize' => 40, 'style' => array('fontSize' => 40)),
            ),
        ),
    ),
);
$plainSiteResult = blocks_engine_figma_transformer_transform_scenegraph($plainSiteScenegraph);
$plainSiteCss = $fileContent($plainSiteResult, 'style.css');
$assert(! str_contains($plainSiteCss, ':root{'), 'design-system-absent-for-plain-site');
$plainCoverage = is_array($plainSiteResult['source_reports']['figma']['html']['design_system']['coverage'] ?? null)
    ? $plainSiteResult['source_reports']['figma']['html']['design_system']['coverage']
    : array();
$assert(0 === ($plainCoverage['frame_count'] ?? -1), 'design-system-plain-site-zero-frames');

// Determinism: re-running yields byte-identical design-system CSS.
$designSystemRerun = blocks_engine_figma_transformer_transform_scenegraph($designSystemScenegraph);
$assert($designSystemCss === $fileContent($designSystemRerun, 'style.css'), 'design-system-css-deterministic');

// PER-FAMILY FONT OVERRIDES — verify that $familyOverrides fills in gaps
// without displacing the GF auto-import for other families. The design uses
// two font families: Inter (resolvable via Google Fonts CDN) and Brand Sans
// (not in GF, supplied via font_family_overrides). Expectations:
//   1. Emitted CSS contains the GF @import for Inter.
//   2. Emitted CSS contains the operator-supplied Brand Sans CSS.
//   3. family_overrides_applied in transform_diagnostics.fonts = ['Brand Sans'].
//   4. Inter is not in missing_css (unresolved_families).
//   5. Brand Sans is not in missing_css (unresolved_families).
$fontFamilyOverridesResult = blocks_engine_figma_transformer_transform_scenegraph(
    array(
        'name'  => 'Per-Family Font Override Fixture',
        'nodes' => array(
            array(
                'id'       => 'pffo:frame',
                'type'     => 'FRAME',
                'name'     => 'Hero',
                'width'    => 1200,
                'height'   => 600,
                'children' => array(
                    array(
                        'id'         => 'pffo:text-inter',
                        'type'       => 'TEXT',
                        'name'       => 'Inter Heading',
                        'characters' => 'Hello World',
                        'fontName'   => array('family' => 'Inter', 'style' => 'Regular'),
                        'fontSize'   => 32,
                        'fontWeight' => 400,
                    ),
                    array(
                        'id'         => 'pffo:text-brand',
                        'type'       => 'TEXT',
                        'name'       => 'Brand Heading',
                        'characters' => 'Brand Copy',
                        'fontName'   => array('family' => 'Brand Sans', 'style' => 'Regular'),
                        'fontSize'   => 24,
                        'fontWeight' => 400,
                    ),
                ),
            ),
        ),
    ),
    array(
        'font_family_overrides' => array(
            'brand sans' => "@import url('https://api.fontshare.com/v2/css?f[]=brand-sans@400&display=swap');",
        ),
    )
);
$fontFamilyOverridesCss = $fileContent($fontFamilyOverridesResult, 'style.css');
$fontFamilyOverridesFonts = $fontFamilyOverridesResult['source_reports']['figma']['html']['transform_diagnostics']['fonts'] ?? array();
$assert('success' === ($fontFamilyOverridesResult['status'] ?? null), 'per-family-override-transform-success');
$assert(str_contains($fontFamilyOverridesCss, 'fonts.googleapis.com'), 'per-family-override-css-contains-gf-import');
$assert(str_contains($fontFamilyOverridesCss, 'Inter'), 'per-family-override-css-gf-import-includes-inter');
$assert(str_contains($fontFamilyOverridesCss, "api.fontshare.com"), 'per-family-override-css-contains-operator-css');
$assert(array('Brand Sans') === ($fontFamilyOverridesFonts['family_overrides_applied'] ?? null), 'per-family-override-family-overrides-applied');
$assert(! in_array('Inter', $fontFamilyOverridesFonts['missing_css'] ?? array(), true), 'per-family-override-inter-not-unresolved');
$assert(! in_array('Brand Sans', $fontFamilyOverridesFonts['missing_css'] ?? array(), true), 'per-family-override-brand-sans-not-unresolved');

// HIDDEN LAYER SKIP: a designer-hidden child (node-level visible:false) must
// not emit to HTML, while a sibling without an explicit visible:false renders.
$hiddenLayerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Hidden Layer Skip Fixture',
    'nodes' => array(
        array(
            'id'         => 'vis:frame',
            'type'       => 'FRAME',
            'name'       => 'Visibility frame',
            'width'      => 400,
            'height'     => 200,
            'layoutMode' => 'VERTICAL',
            'children'   => array(
                array(
                    'id'         => 'vis:visible-child',
                    'type'       => 'TEXT',
                    'name'       => 'Shown copy',
                    'characters' => 'Visible content stays',
                ),
                array(
                    'id'         => 'vis:hidden-child',
                    'type'       => 'TEXT',
                    'name'       => 'Hidden copy',
                    'visible'    => false,
                    'characters' => 'Hidden content must vanish',
                ),
            ),
        ),
    ),
));
$hiddenLayerHtml = $fileContent($hiddenLayerResult, 'index.html');
$assert('success' === ($hiddenLayerResult['status'] ?? null), 'hidden-layer-skip-transform-success');
$assert(str_contains($hiddenLayerHtml, 'data-figma-node-id="vis:visible-child"'), 'visible-sibling-emitted');
$assert(str_contains($hiddenLayerHtml, 'Visible content stays'), 'visible-sibling-text-emitted');
$assert(! str_contains($hiddenLayerHtml, 'data-figma-node-id="vis:hidden-child"'), 'hidden-node-id-not-emitted');
$assert(! str_contains($hiddenLayerHtml, 'Hidden content must vanish'), 'hidden-node-text-not-emitted');

// --- Decoded-but-dropped trio (coverage issue #328) ----------------------
// paragraphIndent, textAutoResize, and stackCounterSpacing are decoded by the
// Kiwi parser but were never read by the normalizer. These contracts assert the
// exact CSS the wiring now emits.

// paragraphIndent → CSS text-indent on the text style declarations.
$paragraphIndentResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Paragraph Indent',
    'nodes' => array(
        array(
            'id'         => 'pi:root',
            'type'       => 'FRAME',
            'name'       => 'Indent Root',
            'width'      => 400,
            'height'     => 200,
            'layoutMode' => 'VERTICAL',
            'children'   => array(
                array(
                    'id'              => 'pi:text',
                    'type'            => 'TEXT',
                    'name'            => 'Indented Copy',
                    'text'            => 'Indented paragraph',
                    'paragraphIndent' => 16,
                ),
            ),
        ),
    ),
));
$paragraphIndentCss = $fileContent($paragraphIndentResult, 'style.css');
$assert('success' === ($paragraphIndentResult['status'] ?? null), 'paragraph-indent-transform-success');
$assert(str_contains($paragraphIndentCss, 'text-indent:16px'), 'paragraph-indent-emits-text-indent');

// textAutoResize → content sizing. WIDTH_AND_HEIGHT hugs both axes; HEIGHT keeps
// the fixed width and lets the height hug content.
$textAutoResizeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Text Auto Resize',
    'nodes' => array(
        array(
            'id'         => 'tar:root',
            'type'       => 'FRAME',
            'name'       => 'Auto Resize Root',
            'width'      => 600,
            'height'     => 400,
            'layoutMode' => 'VERTICAL',
            'children'   => array(
                array(
                    'id'             => 'tar:hug',
                    'type'           => 'TEXT',
                    'name'           => 'Hug Copy',
                    'text'           => 'Hug both axes',
                    'width'          => 200,
                    'height'         => 50,
                    'textAutoResize' => 'WIDTH_AND_HEIGHT',
                ),
                array(
                    'id'             => 'tar:autoheight',
                    'type'           => 'TEXT',
                    'name'           => 'Auto Height Copy',
                    'text'           => 'Fixed width auto height',
                    'width'          => 180,
                    'height'         => 50,
                    'textAutoResize' => 'HEIGHT',
                ),
            ),
        ),
    ),
));
$textAutoResizeCss = $fileContent($textAutoResizeResult, 'style.css');
$assert('success' === ($textAutoResizeResult['status'] ?? null), 'text-auto-resize-transform-success');
$assert(str_contains($textAutoResizeCss, 'width:fit-content;height:fit-content'), 'text-auto-resize-width-and-height-hugs-both-axes');
$assert(! str_contains($textAutoResizeCss, 'width:200px'), 'text-auto-resize-width-and-height-omits-fixed-width');
$assert(str_contains($textAutoResizeCss, 'width:180px;height:fit-content'), 'text-auto-resize-height-keeps-fixed-width-auto-height');

// stackCounterSpacing → two-value CSS gap on a wrapping Auto Layout. The cross-
// axis (counter) spacing is the row gap and the main-axis spacing the column gap
// for a wrapping flex row.
$counterSpacingResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Counter Axis Spacing',
    'nodes' => array(
        array(
            'id'                  => 'cas:root',
            'type'                => 'FRAME',
            'name'                => 'Wrap Root',
            'width'               => 400,
            'height'              => 300,
            'stackMode'           => 'HORIZONTAL',
            'stackWrap'           => 'WRAP',
            'stackSpacing'        => 24,
            'stackCounterSpacing' => 12,
            'children'            => array(
                array('id' => 'cas:a', 'type' => 'RECTANGLE', 'name' => 'Tile A', 'width' => 100, 'height' => 80, 'fill' => array('r' => 1, 'g' => 0, 'b' => 0)),
                array('id' => 'cas:b', 'type' => 'RECTANGLE', 'name' => 'Tile B', 'width' => 100, 'height' => 80, 'fill' => array('r' => 0, 'g' => 1, 'b' => 0)),
            ),
        ),
    ),
));
$counterSpacingCss = $fileContent($counterSpacingResult, 'style.css');
$assert('success' === ($counterSpacingResult['status'] ?? null), 'counter-spacing-transform-success');
$assert(str_contains($counterSpacingCss, 'flex-wrap:wrap'), 'counter-spacing-wraps');
$assert(str_contains($counterSpacingCss, 'gap:12px 24px'), 'counter-spacing-emits-two-value-gap');
$assert(! str_contains($counterSpacingCss, 'gap:24px'), 'counter-spacing-not-single-value-gap');

// ──────────────────────────────────────────────────────────────────────────────
// PARITY FIX 1 — Multi-layer image fills: comma-separated background-image,
// topmost Figma paint first, matching CSS stacking order.
// A node with two IMAGE fills should emit both paths as a comma-separated
// background-image list, with the topmost (last in the fills array) first.
// ──────────────────────────────────────────────────────────────────────────────
$multiLayerImageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Multi-Layer Image Fixture',
    'assets' => array(
        'placeholder-id' => array(
            'name'      => 'placeholder.png',
            'mime_type' => 'image/png',
            'content'   => 'placeholder bytes',
        ),
        'real-photo-id' => array(
            'name'      => 'real-photo.png',
            'mime_type' => 'image/png',
            'content'   => 'real photo bytes',
        ),
    ),
    'nodes' => array(
        array(
            'id'     => 'multi:card',
            'type'   => 'RECTANGLE',
            'name'   => 'Photo Card',
            'width'  => 400,
            'height' => 300,
            // fills[0] = bottom layer (placeholder), fills[1] = topmost (real photo).
            // Figma stores fills bottom→top; CSS background-image is top→bottom.
            'fills' => array(
                array('type' => 'IMAGE', 'ref' => 'placeholder-id'),
                array('type' => 'IMAGE', 'ref' => 'real-photo-id'),
            ),
        ),
    ),
));
$multiLayerImageCss = $fileContent($multiLayerImageResult, 'style.css');
$assert('success' === ($multiLayerImageResult['status'] ?? null), 'multi-layer-image-transform-success');
// Both asset blobs must be emitted (both marked used).
$assert(2 === ($multiLayerImageResult['metrics']['asset_count'] ?? null), 'multi-layer-image-both-assets-emitted');
// The CSS must carry a comma-separated background-image, topmost fill first.
// Asset names like "real-photo.png" are slugified to "real-photo-png.png" by
// the path normalizer (dots become dashes, then extension is re-appended).
$assert(
    str_contains($multiLayerImageCss, 'background-image:url("assets/real-photo-png.png"),url("assets/placeholder-png.png")'),
    'multi-layer-image-comma-separated-topmost-first'
);

// ──────────────────────────────────────────────────────────────────────────────
// PARITY FIX 2 — ul/ol CSS reset present in BOTH emit() and emitSite() paths.
// ──────────────────────────────────────────────────────────────────────────────
// Single-page path (emit()).
$ulResetSingleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'UL Reset Single-Page Fixture',
    'nodes' => array(
        array(
            'id'   => 'ul:root',
            'type' => 'FRAME',
            'name' => 'Grid Root',
            'width'  => 1440,
            'height' => 900,
            'children' => array(
                array('id' => 'ul:item', 'type' => 'RECTANGLE', 'name' => 'Card', 'width' => 400, 'height' => 300),
            ),
        ),
    ),
));
$ulResetSingleCss = $fileContent($ulResetSingleResult, 'style.css');
$assert('success' === ($ulResetSingleResult['status'] ?? null), 'ul-reset-single-page-transform-success');
$assert(str_contains($ulResetSingleCss, 'ul,ol{margin:0;padding:0;list-style:none}'), 'ul-reset-single-page-present');

// Multi-page / emitSite() path.
$ulResetSiteResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name'   => 'UL Reset Site Fixture',
        'assets' => array(),
        'nodes'  => array(
            array('id' => 'ulsite:frame', 'type' => 'FRAME', 'name' => 'Home Page', 'width' => 1440, 'height' => 900, 'children' => array()),
        ),
    ),
    array(
        'pages' => array(
            array('frame_id' => 'ulsite:frame', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
                array('frame_id' => 'ulsite:frame', 'viewport_width' => 1440.0, 'primary' => true),
            )),
        ),
    )
);
$ulResetSiteCss = '';
foreach ( $ulResetSiteResult['files'] ?? array() as $ulResetSiteFile ) {
    if ( is_array($ulResetSiteFile) && 'style.css' === ($ulResetSiteFile['path'] ?? null) ) {
        $ulResetSiteCss = (string) ($ulResetSiteFile['content'] ?? '');
    }
}
$assert('success' === ($ulResetSiteResult['status'] ?? null), 'ul-reset-site-path-transform-success');
$assert(str_contains($ulResetSiteCss, 'ul,ol{margin:0;padding:0;list-style:none}'), 'ul-reset-site-path-present');

// ──────────────────────────────────────────────────────────────────────────────
// PARITY FIX 3 — Responsive breakpoint keyed at midpoint, not variant width.
// Two-variant case: desktop=1440, mobile=390 → midpoint = 915 (not 390).
// ──────────────────────────────────────────────────────────────────────────────
$midpointBreakpointResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name'   => 'Midpoint Breakpoint Fixture',
        'assets' => array(),
        'nodes'  => array(
            array(
                'id' => 'bp:desktop', 'type' => 'FRAME', 'name' => 'Home Desktop',
                'box' => array('width' => 1440, 'height' => 900),
                'children' => array(
                    array('id' => 'bp:card', 'type' => 'RECTANGLE', 'name' => 'Card', 'box' => array('width' => 1200, 'height' => 400), 'background' => '#ff0000'),
                ),
            ),
            array(
                'id' => 'bp:mobile', 'type' => 'FRAME', 'name' => 'Home Mobile',
                'box' => array('width' => 390, 'height' => 900),
                'children' => array(
                    array('id' => 'bp:card-m', 'type' => 'RECTANGLE', 'name' => 'Card', 'box' => array('width' => 350, 'height' => 400), 'background' => '#ff0000'),
                ),
            ),
        ),
    ),
    array(
        'pages' => array(
            array(
                'frame_id'   => 'bp:desktop',
                'name'       => 'Home',
                'path'       => 'index.html',
                'entrypoint' => true,
                'variants'   => array(
                    array('frame_id' => 'bp:desktop', 'viewport_width' => 1440.0, 'primary' => true),
                    array('frame_id' => 'bp:mobile',  'viewport_width' => 390.0,  'primary' => false),
                ),
            ),
        ),
    )
);
$midpointBreakpointCss = '';
foreach ( $midpointBreakpointResult['files'] ?? array() as $midpointBpFile ) {
    if ( is_array($midpointBpFile) && 'style.css' === ($midpointBpFile['path'] ?? null) ) {
        $midpointBreakpointCss = (string) ($midpointBpFile['content'] ?? '');
    }
}
$assert('success' === ($midpointBreakpointResult['status'] ?? null), 'midpoint-breakpoint-transform-success');
// Midpoint of 1440 and 390 = round((1440+390)/2) = 915.
$assert(str_contains($midpointBreakpointCss, '@media (max-width:915px){'), 'midpoint-breakpoint-keyed-at-midpoint');
// The narrow variant's own width (390) must NOT be the breakpoint.
$assert(! str_contains($midpointBreakpointCss, '@media (max-width:390px){'), 'midpoint-breakpoint-not-variant-own-width');

if ( ! empty($failures) ) {
    fwrite(STDERR, "Figma Transformer contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Figma Transformer contract tests passed.\n");

function blocks_engine_figma_transformer_create_fig_wrapper_fixture(): string
{
    $inner = tempnam(sys_get_temp_dir(), 'blocks-engine-inner-fig-');
    $outer = tempnam(sys_get_temp_dir(), 'blocks-engine-wrapper-fig-');
    if ( false === $inner || false === $outer ) {
        throw new RuntimeException('Could not create temporary fig fixture paths.');
    }

    $canvas = 'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate(json_encode(blocks_engine_figma_transformer_nodes_candidate_fixture(), JSON_THROW_ON_ERROR)))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('{"NODE_CHANGES":'))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate(json_encode(blocks_engine_figma_transformer_node_changes_fixture(), JSON_THROW_ON_ERROR)))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('synthetic kiwi dictionary'))
        . blocks_engine_figma_transformer_kiwi_chunk(blocks_engine_figma_transformer_zstd_fixture_payload());

    $innerZip = new ZipArchive();
    if ( true !== $innerZip->open($inner, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open inner fig ZIP.');
    }
    $innerZip->addFromString('canvas.fig', $canvas);
    $innerZip->addFromString('meta.json', json_encode(array('name' => 'Synthetic Fixture'), JSON_THROW_ON_ERROR));
    $innerZip->addFromString('images/synthetic', 'asset');
    $innerZip->close();

    $outerZip = new ZipArchive();
    if ( true !== $outerZip->open($outer, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open wrapper fig ZIP.');
    }
    $outerZip->addFromString('inner.fig', (string) file_get_contents($inner));
    $outerZip->close();

    @unlink($inner);

    return $outer;
}

function blocks_engine_figma_transformer_create_pending_decoder_fig_wrapper_fixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'blocks-engine-pending-fig-');
    if ( false === $path ) {
        throw new RuntimeException('Could not create temporary pending fig fixture path.');
    }

    $canvas = 'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('synthetic undecoded canvas payload'));

    $zip = new ZipArchive();
    if ( true !== $zip->open($path, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open pending fig ZIP.');
    }
    $zip->addFromString('canvas.fig', $canvas);
    $zip->close();

    return $path;
}

function blocks_engine_figma_transformer_kiwi_chunk(string $payload): string
{
    return pack('V', strlen($payload)) . $payload;
}

function blocks_engine_figma_transformer_wire_varint(int $value): string
{
    $bytes = '';
    do {
        $byte = $value & 0x7f;
        $value = intdiv($value, 128);
        if ( $value > 0 ) {
            $byte |= 0x80;
        }
        $bytes .= chr($byte);
    } while ( $value > 0 );

    return $bytes;
}

function blocks_engine_figma_transformer_wire_varint_signed(int $value): string
{
    return blocks_engine_figma_transformer_wire_varint($value < 0 ? ((~$value) << 1) | 1 : $value << 1);
}

function blocks_engine_figma_transformer_kiwi_string(string $value): string
{
    return $value . "\0";
}

function blocks_engine_figma_transformer_kiwi_schema_field(string $name, int $type, bool $isArray, int $value): string
{
    return blocks_engine_figma_transformer_kiwi_string($name)
        . blocks_engine_figma_transformer_wire_varint_signed($type)
        . chr($isArray ? 1 : 0)
        . blocks_engine_figma_transformer_wire_varint($value);
}

function blocks_engine_figma_transformer_kiwi_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('MessageType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('NODE_CHANGES', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', -6, true, 4);
}

function blocks_engine_figma_transformer_kiwi_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('alpha')
        . blocks_engine_figma_transformer_kiwi_string('beta')
        . blocks_engine_figma_transformer_wire_varint(0);
}

/**
 * Kiwi schema (version-106 shape) that defines a dev-status enum plus a
 * NodeChange/Message pair carrying `sectionStatus`. Proves the field-policy
 * extension (#280) reads the status through the REAL generic decoder.
 */
function blocks_engine_figma_transformer_kiwi_dev_status_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(3)
        // def0: ENUM SectionStatus { BUILD = 1, COMPLETED = 2 } (real Figma enum; BUILD = "Ready for dev")
        . blocks_engine_figma_transformer_kiwi_string('SectionStatus')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('BUILD', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('COMPLETED', 0, false, 2)
        // def1: MESSAGE NodeChange { type, name, sectionStatus }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sectionStatus', 0, false, 3)
        // def2: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 1, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_dev_status_schema_fixture()}:
 * one NodeChange of type SECTION with sectionStatus = COMPLETED (enum value 2).
 */
function blocks_engine_figma_transformer_kiwi_dev_status_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('DOCUMENT')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('SECTION')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Completed Section')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

/**
 * Encode a float using Kiwi's varFloat format (the inverse of
 * {@see FigKiwiByteReader::readVarFloat()}): rotate the IEEE-754 bits left by 9
 * so the sign/exponent land in the low byte, and collapse exact 0.0 to one byte.
 */
function blocks_engine_figma_transformer_kiwi_varfloat(float $value): string
{
    $bits = unpack('V', pack('f', $value));
    $raw = is_array($bits) ? (int) $bits[1] : 0;
    if ( 0 === $raw ) {
        return chr(0);
    }

    $rotated = ( ( $raw << 9 ) & 0xffffffff ) | ( ( $raw >> 23 ) & 0x1ff );
    return pack('V', $rotated);
}

/**
 * Encode a float in the Kiwi "compressed float" form that
 * {@see FigKiwiByteReader::readVarFloat()} consumes: a single 0 byte for zero,
 * otherwise the IEEE-754 bits rotated right by 23 and emitted little-endian.
 */
function blocks_engine_figma_transformer_kiwi_float(float $value): string
{
    if ( 0.0 === $value ) {
        return chr(0);
    }

    $bits = unpack('V', pack('f', $value));
    $ieee = is_array($bits) ? (int) $bits[1] : 0;
    // Inverse of the decoder's rotate-left-by-23 so the round trip reproduces $value.
    $rotated = ( ( $ieee << 9 ) & 0xffffffff ) | ( ( $ieee >> 23 ) & 0x1ff );
    return pack('V', $rotated);
}

/**
 * Kiwi schema (version-106 shape) defining Color/Vector structs, the EffectType
 * enum (with the real `FOREGROUND_BLUR` token), an Effect struct, and a
 * NodeChange/Message pair carrying `effects`. Proves the #328 field-policy
 * additions decode shadows + blur through the REAL generic decoder.
 */
function blocks_engine_figma_transformer_kiwi_effects_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(6)
        // def0: STRUCT Color { float r, g, b, a }
        . blocks_engine_figma_transformer_kiwi_string('Color')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_schema_field('r', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('g', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('b', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('a', -5, false, 4)
        // def1: STRUCT Vector { float x, y }
        . blocks_engine_figma_transformer_kiwi_string('Vector')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('x', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('y', -5, false, 2)
        // def2: ENUM EffectType (real Figma tokens/values)
        . blocks_engine_figma_transformer_kiwi_string('EffectType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_schema_field('INNER_SHADOW', 0, false, 0)
        . blocks_engine_figma_transformer_kiwi_schema_field('DROP_SHADOW', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('FOREGROUND_BLUR', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('BACKGROUND_BLUR', 0, false, 3)
        // def3: STRUCT Effect { EffectType type; Color color; Vector offset; float radius; float spread; bool visible }
        . blocks_engine_figma_transformer_kiwi_string('Effect')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 2, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('color', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('offset', 1, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('radius', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('spread', -5, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('visible', -1, false, 6)
        // def4: MESSAGE NodeChange { type, name, effects[] }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('effects', 3, true, 3)
        // def5: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 4, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_effects_schema_fixture()}:
 * one FRAME NodeChange carrying a DROP_SHADOW (offset 0,6 / radius 6 / black @ 0.5)
 * and a FOREGROUND_BLUR (radius 8). Struct fields decode sequentially.
 */
function blocks_engine_figma_transformer_kiwi_effects_message_fixture(): string
{
    $dropShadow = blocks_engine_figma_transformer_wire_varint(1) // EffectType DROP_SHADOW
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.r
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.g
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.b
        . blocks_engine_figma_transformer_kiwi_varfloat(0.5)   // color.a
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // offset.x
        . blocks_engine_figma_transformer_kiwi_varfloat(6.0)   // offset.y
        . blocks_engine_figma_transformer_kiwi_varfloat(6.0)   // radius
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // spread
        . chr(1);                                              // visible

    $foregroundBlur = blocks_engine_figma_transformer_wire_varint(2) // EffectType FOREGROUND_BLUR
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.r
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.g
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.b
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.a
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // offset.x
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // offset.y
        . blocks_engine_figma_transformer_kiwi_varfloat(8.0)   // radius
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // spread
        . chr(1);                                              // visible

    $nodeChange = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')          // type
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Effects Frame')  // name
        . blocks_engine_figma_transformer_wire_varint(3)                // effects field
        . blocks_engine_figma_transformer_wire_varint(2)                // array length
        . $dropShadow
        . $foregroundBlur
        . blocks_engine_figma_transformer_wire_varint(0);              // end NodeChange

    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('DOCUMENT')      // Message.type
        . blocks_engine_figma_transformer_wire_varint(2)              // nodeChanges field
        . blocks_engine_figma_transformer_wire_varint(1)             // array length
        . $nodeChange
        . blocks_engine_figma_transformer_wire_varint(0);            // end Message
}

/**
 * Kiwi schema defining a StrokeAlign enum plus a NodeChange/Message pair that
 * carries `strokeWeight` (float), `strokeAlign` (enum) and `dashPattern`
 * (float[]). Proves the field-policy extension (#328) reads stroke geometry
 * through the REAL generic decoder instead of dropping it at the whitelist.
 */
function blocks_engine_figma_transformer_kiwi_stroke_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(3)
        // def0: ENUM StrokeAlign { CENTER = 1, INSIDE = 2, OUTSIDE = 3 }
        . blocks_engine_figma_transformer_kiwi_string('StrokeAlign')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('CENTER', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('INSIDE', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('OUTSIDE', 0, false, 3)
        // def1: MESSAGE NodeChange { type, name, strokeWeight, strokeAlign, dashPattern[] }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('strokeWeight', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('strokeAlign', 0, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('dashPattern', -5, true, 5)
        // def2: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 1, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_stroke_schema_fixture()}:
 * one RECTANGLE NodeChange with strokeWeight = 3, strokeAlign = INSIDE and a
 * dashPattern of [4, 2].
 */
function blocks_engine_figma_transformer_kiwi_stroke_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('DOCUMENT')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        // NodeChange[0]
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('RECTANGLE')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Bordered Rect')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_float(3.0)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_float(4.0)
        . blocks_engine_figma_transformer_kiwi_float(2.0)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

/**
 * Kiwi schema for component-property text overrides. The field names and nested
 * shape match the FSE Pilot production schema: instance assignments carry
 * ComponentPropAssignment.defID/value.textValue.characters while master text
 * nodes carry ComponentPropRef.defID/componentPropNodeField = TEXT_DATA.
 */
function blocks_engine_figma_transformer_kiwi_component_prop_schema_fixture(): string
{
    $field = 'blocks_engine_figma_transformer_kiwi_schema_field';
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $varint = 'blocks_engine_figma_transformer_wire_varint';

    return $varint(8)
        // def0: ENUM ComponentPropNodeField { TEXT_DATA = 1 }
        . $str('ComponentPropNodeField') . chr(0) . $varint(1)
        . $field('TEXT_DATA', 0, false, 1)
        // def1: STRUCT GUID { sessionID, localID }
        . $str('GUID') . chr(1) . $varint(2)
        . $field('sessionID', -4, false, 1)
        . $field('localID', -4, false, 2)
        // def2: MESSAGE TextData { characters }
        . $str('TextData') . chr(2) . $varint(1)
        . $field('characters', -6, false, 1)
        // def3: MESSAGE ComponentPropValue { textValue }
        . $str('ComponentPropValue') . chr(2) . $varint(1)
        . $field('textValue', 2, false, 2)
        // def4: MESSAGE ComponentPropAssignment { defID, value }
        . $str('ComponentPropAssignment') . chr(2) . $varint(2)
        . $field('defID', 1, false, 1)
        . $field('value', 3, false, 2)
        // def5: MESSAGE ComponentPropRef { defID, componentPropNodeField }
        . $str('ComponentPropRef') . chr(2) . $varint(2)
        . $field('defID', 1, false, 2)
        . $field('componentPropNodeField', 0, false, 4)
        // def6: MESSAGE NodeChange { type, name, componentPropAssignments[], componentPropRefs[], pluginData }
        . $str('NodeChange') . chr(2) . $varint(5)
        . $field('type', -6, false, 1)
        . $field('name', -6, false, 2)
        . $field('componentPropAssignments', 4, true, 3)
        . $field('componentPropRefs', 5, true, 4)
        . $field('pluginData', -2, true, 5)
        // def7: MESSAGE Message { type, nodeChanges[] }
        . $str('Message') . chr(2) . $varint(2)
        . $field('type', -6, false, 1)
        . $field('nodeChanges', 6, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_component_prop_schema_fixture()}.
 */
function blocks_engine_figma_transformer_kiwi_component_prop_message_fixture(): string
{
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $v = 'blocks_engine_figma_transformer_wire_varint';

    $defId = $v(9) . $v(10); // GUID struct body; structs carry no terminator.
    $textData = $v(1) . $str('Selected label') . $v(0);
    $value = $v(2) . $textData . $v(0);
    $assignment = $v(1) . $defId
        . $v(2) . $value
        . $v(0);
    $ref = $v(2) . $defId
        . $v(4) . $v(1)
        . $v(0);
    $node = $v(1) . $str('INSTANCE')
        . $v(2) . $str('Component Property Instance')
        . $v(3) . $v(1) . $assignment
        . $v(4) . $v(1) . $ref
        . $v(5) . $v(3) . 'raw'
        . $v(0);

    return $v(1) . $str('DOCUMENT')
        . $v(2) . $v(1) . $node
        . $v(0);
}

/**
 * Kiwi schema (version-106 shape) that defines the prototype-link surface (#328):
 * a `Hyperlink` struct plus the `PrototypeInteraction`/`PrototypeAction` graph
 * hanging off `NodeChange`, with the real Figma `ConnectionType`/`NavigationType`
 * enums. Proves the field-policy additions read links through the REAL decoder.
 */
function blocks_engine_figma_transformer_kiwi_link_schema_fixture(): string
{
    $field = 'blocks_engine_figma_transformer_kiwi_schema_field';
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $varint = 'blocks_engine_figma_transformer_wire_varint';

    return $varint(10)
        // def0: STRUCT GUID { sessionID, localID }
        . $str('GUID') . chr(1) . $varint(2)
        . $field('sessionID', -4, false, 1)
        . $field('localID', -4, false, 2)
        // def1: ENUM InteractionType { ON_CLICK = 0 }
        . $str('InteractionType') . chr(0) . $varint(1)
        . $field('ON_CLICK', 0, false, 0)
        // def2: ENUM ConnectionType { NONE = 0, INTERNAL_NODE = 1, URL = 2 }
        . $str('ConnectionType') . chr(0) . $varint(3)
        . $field('NONE', 0, false, 0)
        . $field('INTERNAL_NODE', 0, false, 1)
        . $field('URL', 0, false, 2)
        // def3: ENUM NavigationType { NAVIGATE = 0 }
        . $str('NavigationType') . chr(0) . $varint(1)
        . $field('NAVIGATE', 0, false, 0)
        // def4: MESSAGE PrototypeEvent { interactionType }
        . $str('PrototypeEvent') . chr(2) . $varint(1)
        . $field('interactionType', 1, false, 1)
        // def5: MESSAGE PrototypeAction { transitionNodeID, connectionType, connectionURL, navigationType }
        . $str('PrototypeAction') . chr(2) . $varint(4)
        . $field('transitionNodeID', 0, false, 1)
        . $field('connectionType', 2, false, 7)
        . $field('connectionURL', -6, false, 8)
        . $field('navigationType', 3, false, 10)
        // def6: MESSAGE PrototypeInteraction { event, actions[] }
        . $str('PrototypeInteraction') . chr(2) . $varint(2)
        . $field('event', 4, false, 2)
        . $field('actions', 5, true, 3)
        // def7: MESSAGE Hyperlink { url, guid }
        . $str('Hyperlink') . chr(2) . $varint(2)
        . $field('url', -6, false, 1)
        . $field('guid', 0, false, 2)
        // def8: MESSAGE NodeChange { type, name, hyperlink, prototypeInteractions[] }
        . $str('NodeChange') . chr(2) . $varint(4)
        . $field('type', -6, false, 1)
        . $field('name', -6, false, 2)
        . $field('hyperlink', 7, false, 4)
        . $field('prototypeInteractions', 6, true, 5)
        // def9: MESSAGE Message { type, nodeChanges[] }
        . $str('Message') . chr(2) . $varint(2)
        . $field('type', -6, false, 1)
        . $field('nodeChanges', 8, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_link_schema_fixture()}:
 * a TEXT NodeChange with a URL `hyperlink`, and a FRAME NodeChange with an
 * ON_CLICK `prototypeInteractions` entry whose actions carry a URL connection
 * and a node-navigation connection (GUID destination).
 */
function blocks_engine_figma_transformer_kiwi_link_message_fixture(): string
{
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $v = 'blocks_engine_figma_transformer_wire_varint';

    // Hyperlink { url = "https://example.com/about" }
    $hyperlink = $v(1) . $str('https://example.com/about') . $v(0);

    // PrototypeEvent { interactionType = ON_CLICK (0) }
    $event = $v(1) . $v(0) . $v(0);

    // PrototypeAction { connectionType = URL (2), connectionURL }
    $actionUrl = $v(7) . $v(2)
        . $v(8) . $str('https://example.com/cta')
        . $v(0);

    // PrototypeAction { transitionNodeID = GUID{7,42}, connectionType = INTERNAL_NODE (1), navigationType = NAVIGATE (0) }
    $actionNode = $v(1) . $v(7) . $v(42) // GUID struct body (sessionID, localID; structs carry no terminator)
        . $v(7) . $v(1)
        . $v(10) . $v(0)
        . $v(0);

    // PrototypeInteraction { event, actions = [actionUrl, actionNode] }
    $interaction = $v(2) . $event
        . $v(3) . $v(2) . $actionUrl . $actionNode
        . $v(0);

    // NodeChange A: TEXT { hyperlink }
    $nodeA = $v(1) . $str('TEXT')
        . $v(2) . $str('External link')
        . $v(4) . $hyperlink
        . $v(0);

    // NodeChange B: FRAME { prototypeInteractions = [interaction] }
    $nodeB = $v(1) . $str('FRAME')
        . $v(2) . $str('CTA')
        . $v(5) . $v(1) . $interaction
        . $v(0);

    // Message { type = "DOCUMENT", nodeChanges = [nodeA, nodeB] }
    return $v(1) . $str('DOCUMENT')
        . $v(2) . $v(2) . $nodeA . $nodeB
        . $v(0);
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_node_changes_fixture(): array
{
    return array(
        'name'         => 'Decoded Node Changes Fixture',
        'NODE_CHANGES' => array(
            '4:1' => array(
                'node' => array(
                    'id'       => '4:1',
                    'type'     => 'FRAME',
                    'name'     => 'Decoded Landing',
                    'children' => array(
                        array(
                            'id'         => '4:2',
                            'type'       => 'TEXT',
                            'name'       => 'Heading',
                            'characters' => 'Decoded First',
                        ),
                        array(
                            'id'         => '4:3',
                            'type'       => 'TEXT',
                            'name'       => 'Body',
                            'characters' => 'Decoded Second',
                        ),
                        array(
                            'id'   => '4:4',
                            'type' => 'RECTANGLE',
                            'name' => 'Decoded Photo',
                            'fills' => array(
                                array('type' => 'IMAGE', 'imageHash' => 'synthetic'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_nodes_candidate_fixture(): array
{
    return array(
        'name'  => 'Lower Priority Nodes Candidate',
        'nodes' => array(
            array(
                'id'       => 'candidate:1',
                'type'     => 'FRAME',
                'name'     => 'Lower Priority Frame',
                'children' => array(
                    array(
                        'id'         => 'candidate:2',
                        'type'       => 'TEXT',
                        'name'       => 'Lower Priority Text',
                        'characters' => 'This earlier payload must not be selected.',
                    ),
                ),
            ),
        ),
    );
}

function blocks_engine_figma_transformer_zstd_fixture_payload(): string
{
    if ( function_exists('zstd_compress') ) {
        $compressed = zstd_compress('synthetic zstd payload');
        if ( false !== $compressed ) {
            return $compressed;
        }
    }

    return "\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame';
}

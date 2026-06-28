<?php

declare(strict_types=1);

require_once __DIR__ . '/../../figma-transformer.php';
require_once __DIR__ . '/../../scripts/figma-fixture-selection.php';
require_once __DIR__ . '/FixtureMatrixContract.php';
require_once __DIR__ . '/LayoutMismatchContract.php';
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
    foreach ( $result['files'] ?? array() as $file ) {
        if ( $path === ($file['path'] ?? null) ) {
            return (string) ($file['content'] ?? '');
        }
    }

    return '';
};
$findVisualNode = static function (array $result, string $id): ?array {
    foreach ( $result['source_reports']['figma']['html']['visual_node_map'] ?? array() as $node ) {
        if ( is_array($node) && $id === ($node['id'] ?? null) ) {
            return $node;
        }
    }

    return null;
};

$html = $fileContent($result, 'index.html');
$css = $fileContent($result, 'style.css');
$diagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $result['diagnostics'] ?? array()
);
$artifactQualitySignalCodes = static function (array $result): array {
    $signals = $result['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
    return array_values(array_map(
        static fn (array $signal): string => (string) ($signal['code'] ?? ''),
        is_array($signals) ? $signals : array()
    ));
};
$artifactQualitySignal = static function (array $result, string $code): ?array {
    $signals = $result['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
    foreach ( is_array($signals) ? $signals : array() as $signal ) {
        if ( is_array($signal) && $code === ($signal['code'] ?? null) ) {
            return $signal;
        }
    }

    return null;
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
$assert('home-page-desktop' === ($responsiveHomePage['slug'] ?? null), 'page-plan-responsive-primary-drives-slug');
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
$assert(str_contains($fixedFlexCss, '.figma-node-flex-child-a-fixed-child-a{width:70px;height:40px;flex-shrink:0}'), 'fixed-flex-child-does-not-shrink');

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
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-radial-radial-gradient{width:90px;height:70px;background:radial-gradient(circle,rgba(0,255,0,0.5) 25%,rgba(0,0,0,0.5) 100%)}'), 'radial-gradient-background-emits');
$assert(str_contains($gradientPaintCss, '.figma-node-gradient-stroke-gradient-stroke{width:70px;height:60px;border:3px solid transparent;border-image:linear-gradient(180deg,#ffff00 0%,#ff00ff 100%) 1}'), 'linear-gradient-stroke-emits-border-image');
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

$assert(str_contains($layoutFidelityCss, '.figma-node-5-1-layout-frame{width:500px;height:300px;overflow:hidden;position:relative;display:flex;flex-direction:row;justify-content:flex-start;align-items:stretch}'), 'layout-frame-clips-and-positions-absolute-children');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-2-fixed-card{width:100px;height:80px;opacity:0.6;transform:rotate(15deg);flex-shrink:0}'), 'layout-fixed-sizing-and-rotation');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-3-hug-label{width:fit-content;height:fit-content;font-size:12px;flex-shrink:0}'), 'layout-hug-sizing');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-4-fill-panel{width:100%;height:100%;flex-grow:1;flex-shrink:1;align-self:stretch}'), 'layout-fill-sizing-without-source-order');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-7-hug-flex-button{width:160px;height:40px;display:flex;flex-direction:row;justify-content:flex-end;align-items:center;gap:8px;flex-shrink:0}'), 'layout-hug-flex-container-preserves-measured-box');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-5-absolute-badge{width:50px;height:20px;position:absolute;left:20px;right:430px;top:20px;bottom:260px;background:#000000;flex-shrink:0}'), 'layout-absolute-constraints-without-source-z-index');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-6-matrix-transform{width:30px;height:30px;transform:matrix(0,1,-1,0,40,60);transform-origin:0 0;flex-shrink:0}'), 'layout-relative-transform-matrix');
$assert(! str_contains($layoutFidelityCss, 'font-family:Inter') && ! str_contains($layoutFidelityCss, 'body{margin:0;background') && ! str_contains($layoutFidelityCss, 'body{margin:0;color'), 'layout-css-avoids-theme-defaults');

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

blocks_engine_figma_transformer_run_visual_node_map_contract($assert, $findVisualNode, $fileContent);

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
blocks_engine_figma_transformer_run_origin_inference_contract($assert, $fileContent, $findVisualNode);

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
$assert(str_contains($instanceVectorChildrenCss, '.figma-node-icon-one-icon-vector-vector{width:10px;height:10px'), 'instance-vector-css-one');
$assert(str_contains($instanceVectorChildrenCss, '.figma-node-icon-two-icon-vector-vector{width:10px;height:10px'), 'instance-vector-css-two');

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
$assert(str_contains($semanticHtml, '<p class="figma-node-body-p1-intro"'), 'semantic-body-copy-emits-paragraph');
$assert(str_contains($semanticHtml, '<ul class="figma-node-body-cards-feature-cards"'), 'semantic-repeated-items-emit-list');
$assert(str_contains($semanticHtml, '<li class="figma-node-card-1-card-one"'), 'semantic-repeated-item-emits-list-item');
$assert(str_contains($semanticHtml, '<button class="figma-node-body-cta-get-started"'), 'semantic-button-like-node-emits-button');
$assert(! str_contains($semanticHtml, '<div class="figma-node-region-top-top-bar"'), 'semantic-header-not-generic-div');

blocks_engine_figma_transformer_run_fixture_matrix_contract($assert);

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

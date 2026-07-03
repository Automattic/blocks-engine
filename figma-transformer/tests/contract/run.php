<?php

declare(strict_types=1);

require_once __DIR__ . '/../../figma-transformer.php';
require_once __DIR__ . '/../../scripts/figma-fixture-selection.php';
require_once __DIR__ . '/ContractHelpers.php';
require_once __DIR__ . '/ComponentCloneEmissionContract.php';
require_once __DIR__ . '/DiagnosticsEvidenceContract.php';
require_once __DIR__ . '/EffectsContract.php';
require_once __DIR__ . '/FixtureMatrixContract.php';
require_once __DIR__ . '/FormControlContract.php';
require_once __DIR__ . '/GeometryBoxContract.php';
require_once __DIR__ . '/HtmlValidityContract.php';
require_once __DIR__ . '/ImagePaintContract.php';
require_once __DIR__ . '/KiwiSkippedFieldInventoryContract.php';
require_once __DIR__ . '/KiwiParserContract.php';
require_once __DIR__ . '/LayoutMismatchContract.php';
require_once __DIR__ . '/LayoutFrameRoleContract.php';
require_once __DIR__ . '/NodeTraceContract.php';
require_once __DIR__ . '/OriginInferenceContract.php';
require_once __DIR__ . '/ParserParityContract.php';
require_once __DIR__ . '/RenderStyleMismatchContract.php';
require_once __DIR__ . '/SemanticAccessibilityContract.php';
require_once __DIR__ . '/SiteGenerationContract.php';
require_once __DIR__ . '/StackingContextPolicyContract.php';
require_once __DIR__ . '/SyntheticFigKiwiFixtureBuilder.php';
require_once __DIR__ . '/TextLayoutContract.php';
require_once __DIR__ . '/VectorCommandBlobContract.php';
require_once __DIR__ . '/VectorRenderingContract.php';
require_once __DIR__ . '/VisualNodeMapContract.php';

use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Parity\VisualAttributionReportBuilder;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;
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
$longStrokeCommandBlob = chr(1) . pack('g', 0.0) . pack('g', 0.0)
    . str_repeat(chr(2) . pack('g', 1234.123456) . pack('g', 5678.654321), 3200)
    . chr(0);
$oversizedCommandBlob = chr(1) . pack('g', 0.0) . pack('g', 0.0) . str_repeat(chr(2) . pack('g', 1.0) . pack('g', 1.0), 10001);

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
$heroSectionRule = blocks_engine_figma_transformer_contract_css_rule($css, '.figma-node-1-1-hero-section');
$assert(str_contains($heroSectionRule, 'width:100%') && ! str_contains($heroSectionRule, 'max-width:1200px') && str_contains($heroSectionRule, 'min-height:600px') && str_contains($heroSectionRule, 'background:#ffffff') && str_contains($heroSectionRule, 'display:flex') && str_contains($heroSectionRule, 'gap:24px'), 'css-frame-layout-style');
$assert(str_contains($css, '.figma-node-1-2-hero-title{font-size:48px;font-weight:700;color:#1a334d;flex-shrink:0}'), 'css-text-style');

blocks_engine_figma_transformer_run_image_paint_contract($assert, $result, $css, $fileContent);

blocks_engine_figma_transformer_run_layout_frame_role_contract($assert);

blocks_engine_figma_transformer_run_form_control_contract($assert, $fileContent);

blocks_engine_figma_transformer_run_html_validity_contract($assert, $fileContent);

blocks_engine_figma_transformer_run_semantic_accessibility_contract($assert, $fileContent);

blocks_engine_figma_transformer_run_stacking_context_policy_contract($assert);

blocks_engine_figma_transformer_run_vector_command_blob_contract($assert, $oversizedCommandBlob, $longStrokeCommandBlob);

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
$assert(str_contains($css, '.figma-root{position:relative;width:100%;display:flex;flex-direction:column;align-items:center}'), 'css-static-page-root-shell');
$assert(! str_contains($css, 'width:max-content'), 'css-static-page-root-shell-not-fixed-canvas');
$assert(str_contains($heroSectionRule, 'width:100%') && ! str_contains($heroSectionRule, 'max-width:1200px') && str_contains($heroSectionRule, 'min-height:600px'), 'css-page-root-frame-fills-viewport-without-implicit-canvas-cap');
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

blocks_engine_figma_transformer_run_site_generation_quality_contract($assert, $fileContent, $artifactQualitySignalCodes, $artifactQualitySignal);

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
$assert(str_contains($decorativeUnderlayCss, '.figma-node-underlay-parent-flex-hero{width:1000px;height:600px;position:relative;isolation:isolate;display:flex;flex-direction:row}'), 'decorative-underlay-parent-relative');
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

$absoluteDecorativeUnderlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Absolute Decorative Underlay Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'                  => 'abs-underlay:parent',
            'type'                => 'FRAME',
            'name'                => 'Footer row',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 120),
            'layoutMode'          => 'HORIZONTAL',
            'children'            => array(
                array(
                    'id'                  => 'abs-underlay:bg',
                    'type'                => 'RECTANGLE',
                    'name'                => 'Background plate',
                    'absoluteBoundingBox' => array('x' => -20, 'y' => -30, 'width' => 660, 'height' => 180),
                    'layoutPositioning'   => 'ABSOLUTE',
                    'fill'                => array('r' => 0.02, 'g' => 0.04, 'b' => 0.08),
                    'fillGeometry'        => array(array('commandsBlob' => 0)),
                ),
                array(
                    'id'       => 'abs-underlay:copy',
                    'type'     => 'TEXT',
                    'name'     => 'Footer copy',
                    'text'     => 'Footer text stays clickable',
                    'fontSize' => 16,
                ),
            ),
        ),
    ),
));
$absoluteDecorativeUnderlayCss = $fileContent($absoluteDecorativeUnderlayResult, 'style.css');
$absoluteDecorativeUnderlays = $absoluteDecorativeUnderlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$assert(str_contains($absoluteDecorativeUnderlayCss, '.figma-node-abs-underlay-parent-footer-row{width:600px;height:120px;position:relative;isolation:isolate;display:flex;flex-direction:row}'), 'absolute-decorative-underlay-parent-relative');
$assert(str_contains($absoluteDecorativeUnderlayCss, '.figma-node-abs-underlay-bg-background-plate{width:660px;height:180px;position:absolute;left:0px;top:0px;z-index:0;pointer-events:none;background:#050a14}'), 'absolute-decorative-underlay-gets-underlay-z-index');
$assert(str_contains($absoluteDecorativeUnderlayCss, '.figma-node-abs-underlay-copy-footer-copy{position:relative;z-index:1;font-size:16px;flex-shrink:0}'), 'absolute-decorative-underlay-flow-text-stacks-above');
$assert(1 === ($absoluteDecorativeUnderlays['count'] ?? null), 'absolute-decorative-underlay-diagnostic-count');

$freeformDecorativeOverlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Freeform Decorative Overlay Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'freeform-overlay:section',
            'type'     => 'FRAME',
            'name'     => 'Services hero',
            'width'    => 1200,
            'height'   => 520,
            'children' => array(
                array(
                    'id'           => 'freeform-overlay:band',
                    'type'         => 'RECTANGLE',
                    'name'         => 'Diagonal decorative band',
                    'x'            => -120,
                    'y'            => 20,
                    'width'        => 1440,
                    'height'       => 360,
                    'rotation'     => -8,
                    'fill'         => array('r' => 0.90, 'g' => 0.94, 'b' => 0.98),
                    'fillGeometry' => array(array('commandsBlob' => 0)),
                ),
                array(
                    'id'       => 'freeform-overlay:copy',
                    'type'     => 'TEXT',
                    'name'     => 'Hero headline',
                    'x'        => 80,
                    'y'        => 120,
                    'width'    => 620,
                    'height'   => 120,
                    'text'     => 'Services content stays above decorative bands',
                    'fontSize' => 48,
                ),
                array(
                    'id'       => 'freeform-overlay:button',
                    'type'     => 'TEXT',
                    'name'     => 'Hero CTA',
                    'x'        => 80,
                    'y'        => 280,
                    'width'    => 180,
                    'height'   => 42,
                    'text'     => 'Book a visit',
                    'fontSize' => 18,
                ),
            ),
        ),
    ),
));
$freeformDecorativeOverlayCss = $fileContent($freeformDecorativeOverlayResult, 'style.css');
$freeformDecorativeOverlayUnderlays = $freeformDecorativeOverlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $freeformDecorativeOverlayCss, '.figma-node-freeform-overlay-section-services-hero', array('position:relative', 'isolation:isolate'), 'freeform-decorative-overlay-parent-isolated');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $freeformDecorativeOverlayCss, '.figma-node-freeform-overlay-band-diagonal-decorative-band', array('position:absolute', 'left:-120px', 'top:20px', 'z-index:1', 'pointer-events:none'), 'freeform-decorative-overlay-band-underlay');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $freeformDecorativeOverlayCss, '.figma-node-freeform-overlay-copy-hero-headline', array('position:absolute', 'left:80px', 'top:120px', 'z-index:2'), 'freeform-decorative-overlay-text-above');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $freeformDecorativeOverlayCss, '.figma-node-freeform-overlay-button-hero-cta', array('position:absolute', 'left:80px', 'top:280px', 'z-index:3'), 'freeform-decorative-overlay-cta-above');
$assert(1 === ($freeformDecorativeOverlayUnderlays['count'] ?? null), 'freeform-decorative-overlay-underlay-diagnostic-count');

$timelineScaffoldUnderlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Timeline Scaffold Underlay Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'timeline-scaffold:section',
            'type'     => 'FRAME',
            'name'     => 'Treatment timeline',
            'width'    => 900,
            'height'   => 680,
            'children' => array(
                array(
                    'id'       => 'timeline-scaffold:rail',
                    'type'     => 'GROUP',
                    'name'     => 'Vertical line and dots',
                    'x'        => 88,
                    'y'        => 40,
                    'width'    => 32,
                    'height'   => 560,
                    'children' => array(
                        array(
                            'id'           => 'timeline-scaffold:line',
                            'type'         => 'RECTANGLE',
                            'name'         => 'Timeline vertical line',
                            'x'            => 15,
                            'y'            => 0,
                            'width'        => 2,
                            'height'       => 560,
                            'fill'         => array('r' => 0.78, 'g' => 0.82, 'b' => 0.88),
                            'fillGeometry' => array(array('commandsBlob' => 0)),
                        ),
                        array(
                            'id'           => 'timeline-scaffold:dot-1',
                            'type'         => 'ELLIPSE',
                            'name'         => 'Timeline dot 1',
                            'x'            => 0,
                            'y'            => 70,
                            'width'        => 32,
                            'height'       => 32,
                            'fill'         => array('r' => 0.20, 'g' => 0.40, 'b' => 0.68),
                            'fillGeometry' => array(array('commandsBlob' => 0)),
                        ),
                        array(
                            'id'           => 'timeline-scaffold:dot-2',
                            'type'         => 'ELLIPSE',
                            'name'         => 'Timeline dot 2',
                            'x'            => 0,
                            'y'            => 300,
                            'width'        => 32,
                            'height'       => 32,
                            'fill'         => array('r' => 0.20, 'g' => 0.40, 'b' => 0.68),
                            'fillGeometry' => array(array('commandsBlob' => 0)),
                        ),
                    ),
                ),
                array(
                    'id'       => 'timeline-scaffold:step-1',
                    'type'     => 'TEXT',
                    'name'     => 'Consultation step',
                    'x'        => 80,
                    'y'        => 84,
                    'width'    => 640,
                    'height'   => 80,
                    'text'     => 'Consultation and treatment plan',
                    'fontSize' => 24,
                ),
                array(
                    'id'       => 'timeline-scaffold:step-2',
                    'type'     => 'TEXT',
                    'name'     => 'Follow up step',
                    'x'        => 80,
                    'y'        => 314,
                    'width'    => 640,
                    'height'   => 80,
                    'text'     => 'Follow up and maintenance',
                    'fontSize' => 24,
                ),
            ),
        ),
    ),
));
$timelineScaffoldUnderlayCss = $fileContent($timelineScaffoldUnderlayResult, 'style.css');
$timelineScaffoldUnderlays = $timelineScaffoldUnderlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $timelineScaffoldUnderlayCss, '.figma-node-timeline-scaffold-section-treatment-timeline', array('position:relative', 'isolation:isolate'), 'timeline-scaffold-parent-isolated');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $timelineScaffoldUnderlayCss, '.figma-node-timeline-scaffold-rail-vertical-line-and-dots', array('position:absolute', 'left:88px', 'top:40px', 'z-index:1', 'pointer-events:none'), 'timeline-scaffold-rail-underlay');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $timelineScaffoldUnderlayCss, '.figma-node-timeline-scaffold-step-1-consultation-step', array('position:absolute', 'left:80px', 'top:84px', 'z-index:2'), 'timeline-scaffold-step-1-above');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $timelineScaffoldUnderlayCss, '.figma-node-timeline-scaffold-step-2-follow-up-step', array('position:absolute', 'left:80px', 'top:314px', 'z-index:3'), 'timeline-scaffold-step-2-above');
$assert(1 === ($timelineScaffoldUnderlays['count'] ?? null), 'timeline-scaffold-underlay-diagnostic-count');

$fseFooterUnderlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'FSE Footer Underlay Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'                  => 'fse-footer:row',
            'type'                => 'FRAME',
            'name'                => 'Frame 19',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 352, 'width' => 1440, 'height' => 131),
            'layoutMode'          => 'HORIZONTAL',
            'primaryAxisAlignItems' => 'SPACE_BETWEEN',
            'counterAxisAlignItems' => 'CENTER',
            'paddingTop'          => 48,
            'paddingRight'        => 112,
            'paddingBottom'       => 48,
            'paddingLeft'         => 112,
            'children'            => array(
                array(
                    'id'                  => 'fse-footer:bg',
                    'type'                => 'RECTANGLE',
                    'name'                => 'Rectangle 3',
                    'absoluteBoundingBox' => array('x' => 0, 'y' => 288, 'width' => 1440, 'height' => 195),
                    'layoutPositioning'   => 'ABSOLUTE',
                    'constraints'         => array('horizontal' => 'LEFT', 'vertical' => 'TOP_BOTTOM'),
                    'fill'                => array('r' => 0.85, 'g' => 0.85, 'b' => 0.85),
                ),
                array(
                    'id'       => 'fse-footer:logo',
                    'type'     => 'FRAME',
                    'name'     => 'Logo',
                    'width'    => 228,
                    'height'   => 35,
                    'children' => array(
                        array(
                            'id'           => 'fse-footer:logo-mark',
                            'type'         => 'VECTOR',
                            'name'         => 'Union',
                            'width'        => 228,
                            'height'       => 35,
                            'fillGeometry' => array(array('commandsBlob' => 0)),
                        ),
                    ),
                ),
                array(
                    'id'         => 'fse-footer:links',
                    'type'       => 'FRAME',
                    'name'       => 'Frame 29',
                    'width'      => 265,
                    'height'     => 26,
                    'layoutMode' => 'HORIZONTAL',
                    'children'   => array(
                        array('id' => 'fse-footer:about', 'type' => 'TEXT', 'name' => 'Footer text', 'text' => 'About', 'fontSize' => 16),
                        array('id' => 'fse-footer:contact', 'type' => 'TEXT', 'name' => 'Footer text', 'text' => 'Contact', 'fontSize' => 16),
                    ),
                ),
                array('id' => 'fse-footer:powered', 'type' => 'TEXT', 'name' => 'Footer text', 'text' => 'Proudly powered by WordPress.com', 'fontSize' => 16),
            ),
        ),
    ),
));
$fseFooterUnderlayCss = $fileContent($fseFooterUnderlayResult, 'style.css');
$fseFooterUnderlays = $fseFooterUnderlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$fseFooterRowRule = blocks_engine_figma_transformer_contract_css_rule($fseFooterUnderlayCss, '.figma-node-fse-footer-row-frame-19');
$assert(str_contains($fseFooterRowRule, 'width:100%') && ! str_contains($fseFooterRowRule, 'max-width:1440px') && str_contains($fseFooterRowRule, 'height:131px') && str_contains($fseFooterRowRule, 'position:relative') && str_contains($fseFooterRowRule, 'padding-right:max(0px,calc((100% - 1216px) / 2))') && str_contains($fseFooterRowRule, 'padding-left:max(0px,calc((100% - 1216px) / 2))'), 'fse-footer-row-relative');
$assert(str_contains($fseFooterUnderlayCss, '.figma-node-fse-footer-bg-rectangle-3{width:1440px;height:195px;position:absolute;left:0px;top:-64px;bottom:0px;z-index:0;pointer-events:none;background:#d9d9d9}'), 'fse-footer-background-underlay-protected');
$assert(str_contains($fseFooterUnderlayCss, '.figma-node-fse-footer-logo-logo{width:228px;height:35px;position:relative;z-index:1;flex-shrink:0}'), 'fse-footer-logo-stacks-above-underlay');
$assert(str_contains($fseFooterUnderlayCss, '.figma-node-fse-footer-links-frame-29{width:265px;height:26px;position:relative;z-index:1;display:flex;flex-direction:row;flex-shrink:0}'), 'fse-footer-link-row-stacks-above-underlay');
$assert(1 === ($fseFooterUnderlays['count'] ?? null), 'fse-footer-underlay-diagnostic-count');

$newsletterFooterStackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Newsletter Footer Stack Fixture',
    'nodes' => array(
        array(
            'id'       => 'newsletter-stack:footer',
            'type'     => 'FRAME',
            'name'     => 'Footer',
            'width'    => 1440,
            'height'   => 483,
            'children' => array(
                array(
                    'id'       => 'newsletter-stack:card',
                    'type'     => 'FRAME',
                    'name'     => 'Newsletter Signup',
                    'x'        => 112,
                    'y'        => 0,
                    'width'    => 1216,
                    'height'   => 352,
                    'children' => array(
                        array('id' => 'newsletter-stack:headline', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Join the dispatch', 'fontSize' => 40),
                    ),
                ),
                array(
                    'id'         => 'newsletter-stack:row',
                    'type'       => 'FRAME',
                    'name'       => 'Footer Links',
                    'x'          => 0,
                    'y'          => 352,
                    'width'      => 1440,
                    'height'     => 131,
                    'layoutMode' => 'HORIZONTAL',
                    'children'   => array(
                        array('id' => 'newsletter-stack:bg', 'type' => 'RECTANGLE', 'name' => 'Rectangle 3', 'x' => 0, 'y' => -64, 'width' => 1440, 'height' => 195, 'layoutPositioning' => 'ABSOLUTE', 'fill' => array('r' => 1, 'g' => 0.811764717, 'b' => 0)),
                        array('id' => 'newsletter-stack:legal', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Footer links', 'fontSize' => 16),
                    ),
                ),
            ),
        ),
    ),
));
$newsletterFooterStackCss = $fileContent($newsletterFooterStackResult, 'style.css');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $newsletterFooterStackCss, '.figma-node-newsletter-stack-card-newsletter-signup', array('position:absolute', 'z-index:2'), 'newsletter-footer-card-stacks-above-protruding-underlay');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $newsletterFooterStackCss, '.figma-node-newsletter-stack-row-footer-links', array('position:absolute', 'z-index:1'), 'newsletter-footer-row-underlay-stack-contained');
blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $newsletterFooterStackCss, '.figma-node-newsletter-stack-bg-rectangle-3', array('top:-64px', 'z-index:0', 'pointer-events:none'), 'newsletter-footer-protruding-underlay-protected');

$yellowForegroundOverlapResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Yellow Foreground Overlap Fixture',
    'nodes' => array(
        array(
            'id'         => 'paint-style:kiwi-yellow',
            'type'       => 'RECTANGLE',
            'name'       => 'Kiwi Yellow paint style',
            'styleType'  => 'FILL',
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.811764717, 'b' => 0, 'a' => 1))),
        ),
        array(
            'id'         => 'yellow-overlap:parent',
            'type'       => 'FRAME',
            'name'       => 'Featured overlap row',
            'width'      => 1000,
            'height'     => 600,
            'layoutMode' => 'HORIZONTAL',
            'children'   => array(
                array(
                    'id'       => 'yellow-overlap:title',
                    'type'     => 'TEXT',
                    'name'     => 'Featured title',
                    'text'     => 'Featured copy stays behind accent',
                    'fontSize' => 32,
                ),
                array(
                    'id'             => 'yellow-overlap:accent',
                    'type'           => 'FRAME',
                    'name'           => 'Yellow overlap accent',
                    'width'          => 900,
                    'height'         => 520,
                    'styleIdForFill' => 'paint-style:kiwi-yellow',
                    'fillPaints'     => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                    'fillGeometry'   => array(array('path' => 'M 0 0 L 900 0 L 900 520 L 0 520 Z', 'windingRule' => 'NONZERO')),
                ),
            ),
        ),
    ),
));
$yellowForegroundOverlapCss = $fileContent($yellowForegroundOverlapResult, 'style.css');
$yellowForegroundOverlapUnderlays = $yellowForegroundOverlapResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$yellowForegroundOverlapDiagnostics = array_values(array_filter(
    $yellowForegroundOverlapResult['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'figma_local_style_paint_conflict' === ($diagnostic['code'] ?? null)
));
$assert(str_contains($yellowForegroundOverlapCss, '.figma-node-yellow-overlap-accent-yellow-overlap-accent{width:900px;height:520px;background:#ffcf00;flex-shrink:0}'), 'yellow-overlap-style-paint-resolves-without-underlay');
$assert(! str_contains($yellowForegroundOverlapCss, '.figma-node-yellow-overlap-accent-yellow-overlap-accent{width:900px;height:520px;position:absolute'), 'yellow-overlap-foreground-not-decorative-underlay');
$assert(0 === ($yellowForegroundOverlapUnderlays['count'] ?? null), 'yellow-overlap-foreground-underlay-diagnostic-count');
$assert('style' === ($yellowForegroundOverlapDiagnostics[0]['context']['precedence'] ?? null), 'yellow-overlap-style-fill-conflict-diagnostic-precedence');

$multiPageFseFooterUnderlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Multi Page FSE Footer Underlay Fixture',
    'assets' => array(
        array(
            'id'        => 'asset:rectangle-name-collision',
            'name'      => 'Rectangle 3',
            'content'   => 'asset-content',
            'mime_type' => 'image/png',
        ),
    ),
    'nodes'  => array(
        array(
            'id'       => 'multi-fse:canvas',
            'type'     => 'CANVAS',
            'name'     => 'Site',
            'children' => array(
                array(
                    'id'       => 'multi-fse:home',
                    'type'     => 'FRAME',
                    'name'     => 'Home Desktop',
                    'width'    => 1440,
                    'height'   => 600,
                    'children' => array(
                        array(
                            'id'                    => 'multi-fse:row',
                            'type'                  => 'FRAME',
                            'name'                  => 'Frame 19',
                            'absoluteBoundingBox'   => array('x' => 0, 'y' => 352, 'width' => 1440, 'height' => 131),
                            'layoutMode'            => 'HORIZONTAL',
                            'primaryAxisAlignItems' => 'SPACE_BETWEEN',
                            'counterAxisAlignItems' => 'CENTER',
                            'paddingTop'            => 48,
                            'paddingRight'          => 112,
                            'paddingBottom'         => 48,
                            'paddingLeft'           => 112,
                            'children'              => array(
                                array(
                                    'id'                  => 'multi-fse:bg',
                                    'type'                => 'RECTANGLE',
                                    'name'                => 'Rectangle 3',
                                    'absoluteBoundingBox' => array('x' => 0, 'y' => 288, 'width' => 1440, 'height' => 195),
                                    'layoutPositioning'   => 'ABSOLUTE',
                                    'constraints'         => array('horizontal' => 'LEFT', 'vertical' => 'TOP_BOTTOM'),
                                    'fill'                => array('r' => 0.85, 'g' => 0.85, 'b' => 0.85),
                                ),
                                array('id' => 'multi-fse:logo', 'type' => 'VECTOR', 'name' => 'Logo', 'width' => 228, 'height' => 35, 'fillGeometry' => array(array('commandsBlob' => 0))),
                                array('id' => 'multi-fse:about', 'type' => 'TEXT', 'name' => 'Footer text', 'text' => 'About', 'fontSize' => 16),
                                array('id' => 'multi-fse:powered', 'type' => 'TEXT', 'name' => 'Footer text', 'text' => 'Proudly powered by WordPress.com', 'fontSize' => 16),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
), array('multi_page' => true, 'frame_ids' => array('multi-fse:home'), 'entry_frame_id' => 'multi-fse:home'));
$multiPageFseFooterUnderlayCss = $fileContent($multiPageFseFooterUnderlayResult, 'style.css');
$multiPageFseFooterUnderlays = $multiPageFseFooterUnderlayResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$assert(str_contains($multiPageFseFooterUnderlayCss, '.figma-node-multi-fse-bg-rectangle-3{') && str_contains($multiPageFseFooterUnderlayCss, 'z-index:0;pointer-events:none'), 'multi-page-fse-footer-background-underlay-protected-with-asset-name-collision');
$assert(1 === ($multiPageFseFooterUnderlays['count'] ?? null), 'multi-page-fse-footer-underlay-diagnostic-count');

$absoluteTextContentGuardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Absolute Text Content Guard Fixture',
    'nodes' => array(
        array(
            'id'                  => 'abs-textguard:parent',
            'type'                => 'FRAME',
            'name'                => 'Footer row',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 120),
            'layoutMode'          => 'HORIZONTAL',
            'children'            => array(
                array(
                    'id'                  => 'abs-textguard:callout',
                    'type'                => 'TEXT',
                    'name'                => 'Absolute callout',
                    'absoluteBoundingBox' => array('x' => -20, 'y' => -30, 'width' => 660, 'height' => 180),
                    'layoutPositioning'   => 'ABSOLUTE',
                    'text'                => 'Real absolute content',
                ),
                array('id' => 'abs-textguard:sibling', 'type' => 'TEXT', 'name' => 'Sibling copy', 'text' => 'Sibling'),
            ),
        ),
    ),
));
$absoluteTextContentGuardCss = $fileContent($absoluteTextContentGuardResult, 'style.css');
$absoluteTextContentGuardUnderlays = $absoluteTextContentGuardResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
$assert(str_contains($absoluteTextContentGuardCss, '.figma-node-abs-textguard-callout-absolute-callout{width:660px;height:180px;position:absolute;left:0px;top:0px;flex-shrink:0}'), 'absolute-text-content-remains-absolute-content');
$assert(! str_contains($absoluteTextContentGuardCss, '.figma-node-abs-textguard-callout-absolute-callout{width:660px;height:180px;position:absolute;left:0px;top:0px;z-index:0;pointer-events:none}'), 'absolute-text-content-not-hidden-as-underlay');
$assert(0 === ($absoluteTextContentGuardUnderlays['count'] ?? null), 'absolute-text-content-not-decorative-underlay-diagnostic');

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

$rootOffCanvasResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Root Off Canvas Alternate Fixture',
    'nodes' => array(
        array(
            'id'       => 'root-offcanvas:root',
            'type'     => 'FRAME',
            'name'     => 'Page root',
            'width'    => 640,
            'height'   => 480,
            'children' => array(
                array('id' => 'root-offcanvas:image', 'type' => 'RECTANGLE', 'name' => 'Alternate image', 'x' => 0, 'y' => -720, 'width' => 640, 'height' => 240),
                array('id' => 'root-offcanvas:title', 'type' => 'TEXT', 'name' => 'Alternate title', 'characters' => 'Alternate hero', 'x' => 64, 'y' => -360, 'width' => 320, 'height' => 48, 'fontSize' => 24),
                array('id' => 'root-offcanvas:visible', 'type' => 'TEXT', 'name' => 'Visible title', 'characters' => 'Visible hero', 'x' => 64, 'y' => 120, 'width' => 320, 'height' => 48, 'fontSize' => 24),
            ),
        ),
    ),
));
$rootOffCanvasHtml = $fileContent($rootOffCanvasResult, 'index.html');
$rootOffCanvasCss = $fileContent($rootOffCanvasResult, 'style.css');
$rootOffCanvasDiagnostics = $rootOffCanvasResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
$assert(! str_contains($rootOffCanvasHtml, 'Alternate hero') && ! str_contains($rootOffCanvasCss, 'figma-node-root-offcanvas-image'), 'root-off-canvas-unpositioned-children-not-emitted');
$assert(str_contains($rootOffCanvasHtml, 'Visible hero'), 'root-off-canvas-visible-child-emitted');
$assert(2 === ($rootOffCanvasDiagnostics['decision_traces']['reason_counts']['root_off_canvas_child_suppressed'] ?? null), 'root-off-canvas-decision-trace-count');
$assert(0 === ($rootOffCanvasDiagnostics['layout']['off_canvas_visual_node_count'] ?? null), 'root-off-canvas-suppressed-not-visual-warning');
$assert(0 === ($rootOffCanvasDiagnostics['layout']['large_absolute_offset_count'] ?? null), 'root-off-canvas-suppressed-not-large-offset-warning');
$assert(2 === ($rootOffCanvasDiagnostics['layout']['suppressed_large_absolute_offset_count'] ?? null), 'root-off-canvas-suppressed-large-offset-count');
$assert(2 === ($rootOffCanvasDiagnostics['layout']['suppressed_large_absolute_offset_reason_counts']['root_off_canvas_child_suppressed'] ?? null), 'root-off-canvas-suppressed-large-offset-reason-count');
$assert('root_off_canvas_child_suppressed' === ($rootOffCanvasDiagnostics['layout']['suppressed_large_absolute_offset_nodes'][0]['suppression_reason'] ?? null), 'root-off-canvas-suppressed-large-offset-sample-reason');
$assert(! in_array('large_absolute_offsets', $artifactQualitySignalCodes($rootOffCanvasResult), true), 'root-off-canvas-suppressed-no-large-offset-quality-signal');

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

$externalizedVectorPath = 'M 0.0000 0.0000' . str_repeat(' L 10.000001 10.000001', 12000) . ' Z';
$simpleRectNetworkPrefix = hex2bin('0400000004000000000000000000000000000000000000000000000000008043');
$simpleRectNetworkBlob = str_pad(false === $simpleRectNetworkPrefix ? '' : $simpleRectNetworkPrefix, 172, "\0");
blocks_engine_figma_transformer_run_vector_rendering_contract($assert, $fileContent, $findVisualNode, $vectorCommandBlob, $externalizedVectorPath, $simpleRectNetworkBlob);

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

blocks_engine_figma_transformer_run_site_generation_planning_contract($assert, $fileContent, $externalizedVectorPath);

blocks_engine_figma_transformer_run_text_layout_contract($assert, $fileContent, $quadraticCommandBlob);

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

blocks_engine_figma_transformer_run_kiwi_parser_contract($assert, $fileContent);

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
$fixedRootFlexRule = blocks_engine_figma_transformer_contract_css_rule($fixedRootFlexCss, '.figma-node-fixed-root-flex-fixed-root-flex');
$assert(str_contains($fixedRootFlexRule, 'width:100%') && ! str_contains($fixedRootFlexRule, 'max-width:1280px') && str_contains($fixedRootFlexRule, 'height:100px') && str_contains($fixedRootFlexRule, 'display:flex') && str_contains($fixedRootFlexRule, 'flex-direction:column'), 'fixed-root-flex-emits-fixed-height');
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
$fixedPaddingRule = blocks_engine_figma_transformer_contract_css_rule($fixedPaddingClampCss, '.figma-node-padding-frame-impossible-fixed-padding');
$assert(str_contains($fixedPaddingRule, 'width:100%') && ! str_contains($fixedPaddingRule, 'max-width:1280px') && str_contains($fixedPaddingRule, 'height:100px') && str_contains($fixedPaddingRule, 'display:flex') && str_contains($fixedPaddingRule, 'padding-top:50px') && str_contains($fixedPaddingRule, 'padding-bottom:50px'), 'fixed-padding-clamped-css');
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
$stylePaintDiagnostics = array_values(array_filter(
    $stylePaintResult['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'figma_local_style_paint_conflict' === ($diagnostic['code'] ?? null)
));
$assert(str_contains($stylePaintCss, '.figma-node-style-button-styled-button{width:100px;height:40px;background:#1acc80}'), 'style-paint-overrides-stale-inline-fill');
$assert('style' === ($stylePaintDiagnostics[0]['context']['precedence'] ?? null), 'style-paint-conflict-diagnostic-precedence');

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

blocks_engine_figma_transformer_run_text_style_contract($assert, $fileContent, $artifactQualitySignal, $artifactQualitySignalCodes);

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

// Paint-level blendMode maps to CSS background-blend-mode for image layers.
// NORMAL remains omitted, while mixed image layers keep one blend token per
// background-image layer so CSS applies the non-default mode to the right layer.
$paintBlendModeResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'   => 'Paint Blend Mode Fixture',
    'assets' => array(
        'blend-top'    => array('mime_type' => 'image/png', 'content' => 'top image'),
        'blend-bottom' => array('mime_type' => 'image/png', 'content' => 'bottom image'),
        'blend-normal' => array('mime_type' => 'image/png', 'content' => 'normal image'),
    ),
    'nodes'  => array(
        array(
            'id'         => 'paint-blend:mixed',
            'type'       => 'RECTANGLE',
            'name'       => 'Mixed paint blends',
            'width'      => 100,
            'height'     => 80,
            'fillPaints' => array(
                array('type' => 'IMAGE', 'imageRef' => 'blend-bottom', 'blendMode' => 'NORMAL'),
                array('type' => 'IMAGE', 'imageRef' => 'blend-top', 'blendMode' => 'MULTIPLY'),
            ),
        ),
        array(
            'id'         => 'paint-blend:normal',
            'type'       => 'RECTANGLE',
            'name'       => 'Normal paint blend',
            'width'      => 100,
            'height'     => 80,
            'fillPaints' => array(
                array('type' => 'IMAGE', 'imageRef' => 'blend-normal', 'blendMode' => 'NORMAL'),
            ),
        ),
    ),
));
$paintBlendModeCss = $fileContent($paintBlendModeResult, 'style.css');
$assert(str_contains($paintBlendModeCss, '.figma-node-paint-blend-mixed-mixed-paint-blends{width:100px;height:80px;background-image:url("assets/blend-top.png"),url("assets/blend-bottom.png");background-blend-mode:multiply,normal;background-size:cover;background-position:center}'), 'paint-blend-mode-image-layers-emit-background-blend');
$assert(1 === substr_count($paintBlendModeCss, 'background-blend-mode'), 'paint-blend-mode-normal-omits-background-blend');

blocks_engine_figma_transformer_run_inline_text_style_contract($assert, $fileContent);

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

$multiPageGoogleFontsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Multi Page Google Fonts Fixture',
    'nodes' => array(
        array(
            'id'       => 'mpgf:home',
            'type'     => 'FRAME',
            'name'     => 'Home Desktop',
            'width'    => 1200,
            'height'   => 400,
            'children' => array(
                array('id' => 'mpgf:home-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Home', 'fontName' => array('family' => 'Barlow Condensed', 'style' => 'Bold'), 'fontSize' => 48),
                array('id' => 'mpgf:home-body', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Home body', 'fontName' => array('family' => 'Plus Jakarta Sans', 'style' => 'Medium'), 'fontSize' => 18),
            ),
        ),
        array(
            'id'       => 'mpgf:about',
            'type'     => 'FRAME',
            'name'     => 'About Desktop',
            'width'    => 1200,
            'height'   => 400,
            'children' => array(
                array('id' => 'mpgf:about-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'About', 'fontName' => array('family' => 'Plus Jakarta Sans', 'style' => 'Bold'), 'fontSize' => 40),
                array('id' => 'mpgf:about-body', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'About body', 'fontName' => array('family' => 'Plus Jakarta Sans', 'style' => 'Medium'), 'fontSize' => 18),
            ),
        ),
    ),
), array('multi_page' => true, 'frame_ids' => array('mpgf:home', 'mpgf:about'), 'entry_frame_id' => 'mpgf:home'));
$multiPageGoogleFontsCss = $fileContent($multiPageGoogleFontsResult, 'style.css');
$assert(str_contains($multiPageGoogleFontsCss, "@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700&family=Plus+Jakarta+Sans:wght@500;700&display=swap');"), 'multi-page-google-fonts-import-keeps-semicolon-weights');
$assert(! str_contains($multiPageGoogleFontsCss, "family=Plus+Jakarta+Sans:wght@500\n700"), 'multi-page-google-fonts-import-not-split-at-weight-semicolon');

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
                array('id' => 'sf:4', 'type' => 'TEXT', 'name' => 'SF Mono code', 'characters' => 'SF Mono code', 'fontName' => array('family' => 'SF Mono', 'style' => 'Regular'), 'fontSize' => 14),
                array('id' => 'sf:5', 'type' => 'TEXT', 'name' => 'SF Pro Text label', 'characters' => 'SF Pro Text label', 'fontName' => array('family' => 'SF Pro Text', 'style' => 'Semibold'), 'fontSize' => 14),
                array('id' => 'sf:6', 'type' => 'TEXT', 'name' => 'SF UI Text label', 'characters' => 'SF UI Text label', 'fontName' => array('family' => 'SF UI Text', 'style' => 'Regular'), 'fontSize' => 14),
                array('id' => 'sf:7', 'type' => 'TEXT', 'name' => 'Brand heading', 'characters' => 'Brand heading', 'fontName' => array('family' => 'Acme Brand Sans', 'style' => 'Regular'), 'fontSize' => 24),
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
$assert('web_safe' === ($systemFontCoverage['SF Mono']['resolution'] ?? null), 'sf-mono-resolves-web-safe');
$assert(false === ($systemFontCoverage['SF Mono']['needs_operator_font'] ?? null), 'sf-mono-no-operator-font-needed');
$assert('"SF Mono", Menlo, Monaco, Consolas, "Courier New", monospace' === ($systemFontCoverage['SF Mono']['fallback_stack'] ?? null), 'sf-mono-system-fallback-stack');
$assert('web_safe' === ($systemFontCoverage['SF Pro Text']['resolution'] ?? null), 'sf-pro-text-resolves-web-safe');
$assert(false === ($systemFontCoverage['SF Pro Text']['needs_operator_font'] ?? null), 'sf-pro-text-no-operator-font-needed');
$assert('"SF Pro Text", "SF Pro Display", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' === ($systemFontCoverage['SF Pro Text']['fallback_stack'] ?? null), 'sf-pro-text-system-fallback-stack');
$assert('web_safe' === ($systemFontCoverage['SF UI Text']['resolution'] ?? null), 'sf-ui-text-resolves-web-safe');
$assert(false === ($systemFontCoverage['SF UI Text']['needs_operator_font'] ?? null), 'sf-ui-text-no-operator-font-needed');
$assert('"SF UI Text", "SF Pro Text", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' === ($systemFontCoverage['SF UI Text']['fallback_stack'] ?? null), 'sf-ui-text-system-fallback-stack');
$assert(array() === array_values(array_intersect(array('Helvetica Neue', 'Segoe UI', 'SF Mono', 'SF Pro Text', 'SF UI Text'), $systemFontFonts['missing_css'] ?? array())), 'system-fonts-not-in-missing-css');
$assert(! str_contains($systemFontCss, 'Helvetica+Neue') && ! str_contains($systemFontCss, 'Segoe+UI') && ! str_contains($systemFontCss, 'SF+Mono') && ! str_contains($systemFontCss, 'SF+Pro+Text') && ! str_contains($systemFontCss, 'SF+UI+Text') && ! str_contains($systemFontCss, 'fonts.googleapis.com'), 'system-fonts-emit-no-cdn-import');
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

$assert(str_contains($layoutFidelityCss, '.figma-node-5-1-layout-frame{width:500px;height:300px;overflow:hidden;position:relative;isolation:isolate;display:flex;flex-direction:row;justify-content:flex-start;align-items:stretch}'), 'layout-frame-clips-and-positions-absolute-children');
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
blocks_engine_figma_transformer_run_component_clone_emission_contract($assert);
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
            'stackHorizontalGap' => 14,
            'children'  => array(
                array(
                    'id'                    => 'kiwi-layout:fill-item',
                    'type'                  => 'RECTANGLE',
                    'name'                  => 'Fill item',
                    'width'                 => 80,
                    'height'                => 40,
                    'stackChildPrimaryGrow' => 1,
                    'layoutOrder'           => 2,
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
                    'stackChildOrder'     => 1,
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
// Kiwi CENTER == fixed child-center offset from parent center via calc(), no transform.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-center-badge-center-badge{width:50px;height:20px;position:absolute;left:calc(50% - 25px);top:calc(50% - 10px)}'), 'kiwi-constraint-center-uses-child-center-calc-offset');
// stackHorizontalGap aliases to the normal item gap even when stackSpacing is absent.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-flexframe-kiwi-flex-frame{width:300px;height:100px;display:flex;flex-direction:row;gap:14px}'), 'kiwi-stack-horizontal-gap-alias-emits-gap');
// stackChildPrimaryGrow -> flex-grow; minSize/maxSize -> min/max width/height.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-fill-item-fill-item{width:80px;height:40px;min-width:100px;max-width:200px;min-height:20px;max-height:60px;flex-grow:1;order:2}'), 'kiwi-grow-min-max-size-and-order');
// stackPrimarySizing RESIZE_TO_FIT -> HUG main axis; stackCounterSizing FIXED -> fixed cross axis.
$assert(str_contains($kiwiLayoutFieldsCss, '.figma-node-kiwi-layout-hug-frame-hug-frame{width:max-content;height:40px;display:flex;flex-direction:row;flex-shrink:0;order:1}'), 'kiwi-stack-sizing-bridges-to-flex-sizing-and-order');

$centerOversizedClippedResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Center Oversized Clipped Fixture',
    'nodes' => array(
        array(
            'id'                  => 'center-clip:parent',
            'type'                => 'FRAME',
            'name'                => 'Clipped logo parent',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 200, 'height' => 80),
            'isClip'              => true,
            'children'            => array(
                array(
                    'id'                   => 'center-clip:logo-piece',
                    'type'                 => 'RECTANGLE',
                    'name'                 => 'Oversized logo piece',
                    'absoluteBoundingBox'  => array('x' => -50, 'y' => -20, 'width' => 300, 'height' => 120),
                    'stackPositioning'     => 'ABSOLUTE',
                    'horizontalConstraint' => 'CENTER',
                    'verticalConstraint'   => 'CENTER',
                ),
            ),
        ),
    ),
));
$centerOversizedClippedCss = $fileContent($centerOversizedClippedResult, 'style.css');
$assert(str_contains($centerOversizedClippedCss, '.figma-node-center-clip-parent-clipped-logo-parent{width:200px;height:80px;overflow:hidden;position:relative}'), 'center-oversized-clipped-parent-overflow-hidden');
$assert(str_contains($centerOversizedClippedCss, '.figma-node-center-clip-logo-piece-oversized-logo-piece{width:300px;height:120px;position:absolute;left:calc(50% - 100px);top:calc(50% - 40px)}'), 'center-oversized-clipped-child-uses-child-center-calc');

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
$assert(str_contains($nestedMissingOriginCss, '.figma-node-missing-origin-button-shell-button-shell{width:220px;height:64px;position:relative;isolation:isolate}'), 'nested-missing-origin-parent-becomes-freeform');
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
$selectedFrameComponentCloneResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Selected Frame Component Clone Fixture',
    'nodes' => array(
        array(
            'id'                  => 'clone-rebase:component',
            'type'                => 'COMPONENT',
            'name'                => 'Featured card component',
            'key'                 => 'featured-card-key',
            'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 200),
            'children'            => array(
                array(
                    'id'                  => 'clone-rebase:component/content',
                    'type'                => 'FRAME',
                    'name'                => 'Content frame',
                    'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 200),
                    'layoutMode'          => 'VERTICAL',
                    'children'            => array(
                        array(
                            'id'                  => 'clone-rebase:component/title',
                            'type'                => 'TEXT',
                            'name'                => 'Title',
                            'characters'          => 'Component title',
                            'absoluteBoundingBox' => array('x' => 24, 'y' => 24, 'width' => 180, 'height' => 32),
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'                  => 'clone-rebase:page',
            'type'                => 'FRAME',
            'name'                => 'Selected page',
            'absoluteBoundingBox' => array('x' => 3000, 'y' => 400, 'width' => 800, 'height' => 600),
            'children'            => array(
                array(
                    'id'                  => 'clone-rebase:instance',
                    'type'                => 'INSTANCE',
                    'name'                => 'Featured card instance',
                    'componentId'         => 'featured-card-key',
                    'absoluteBoundingBox' => array('x' => 3120, 'y' => 480, 'width' => 400, 'height' => 200),
                    'layoutPositioning'   => 'ABSOLUTE',
                ),
            ),
        ),
    ),
), array('frame_id' => 'clone-rebase:page'));
$selectedFrameComponentCloneCss = $fileContent($selectedFrameComponentCloneResult, 'style.css');
$selectedFrameComponentCloneContent = $findVisualNode($selectedFrameComponentCloneResult, 'clone-rebase:instance/clone-rebase:component/content');
$assert(str_contains($selectedFrameComponentCloneCss, '.figma-node-clone-rebase-instance-clone-rebase-component-content-content-frame{width:400px;height:200px;position:absolute;left:0px;top:0px'), 'selected-frame-rebase-keeps-component-clone-child-local-css');
blocks_engine_figma_transformer_contract_assert_node_rect($assert, $selectedFrameComponentCloneContent, array('x' => 120.0, 'y' => 80.0, 'width' => 400.0, 'height' => 200.0), 'selected-frame-rebase-keeps-component-clone-child-local-visual-map');
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

$nestedImageOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nested Image Override Fixture',
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 700, 'localID' => 1),
            'type'     => 'COMPONENT',
            'name'     => 'Image component',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 700, 'localID' => 2),
                    'type'       => 'RECTANGLE',
                    'name'       => 'Image',
                    'width'      => 100,
                    'height'     => 50,
                    'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'default-image')),
                ),
            ),
        ),
        array(
            'guid'     => array('sessionID' => 700, 'localID' => 3),
            'type'     => 'COMPONENT',
            'name'     => 'Preview component',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 700, 'localID' => 4),
                    'type'       => 'INSTANCE',
                    'name'       => 'Image slot',
                    'symbolData' => array('symbolID' => array('sessionID' => 700, 'localID' => 1)),
                ),
            ),
        ),
        array(
            'id'         => 'instance:preview',
            'type'       => 'INSTANCE',
            'name'       => 'Preview instance',
            'symbolData' => array(
                'symbolID' => array('sessionID' => 700, 'localID' => 3),
                'symbolOverrides' => array(
                    array(
                        'guidPath'   => array('guids' => array(array('sessionID' => 700, 'localID' => 4), array('sessionID' => 700, 'localID' => 2))),
                        'fillPaints' => array(
                            array('type' => 'IMAGE', 'imageRef' => 'default-image'),
                            array('type' => 'IMAGE', 'imageRef' => 'override-image'),
                        ),
                    ),
                ),
            ),
        ),
    ),
    'assets' => array(
        array('id' => 'default-image', 'content' => 'default'),
        array('id' => 'override-image', 'content' => 'override'),
    ),
));
$nestedImageOverrideCss = $fileContent($nestedImageOverrideResult, 'style.css');
$assert(str_contains($nestedImageOverrideCss, '.figma-node-instance-preview-700-4-700-2-image'), 'nested-image-override-emits-nested-image-node');
$assert(str_contains($nestedImageOverrideCss, 'background-image:url("assets/override-image.bin")'), 'nested-image-override-replaces-source-image-paint');
$assert(! str_contains($nestedImageOverrideCss, 'background-image:url("assets/override-image.bin"),url("assets/default-image.bin")'), 'nested-image-override-drops-stale-source-image-paint');

$styleBackedImageOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Style Backed Image Override Fixture',
    'nodes' => array(
        array(
            'guid'       => array('sessionID' => 701, 'localID' => 1),
            'type'       => 'RECTANGLE',
            'name'       => 'Image style source',
            'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'styled-default-image')),
        ),
        array(
            'guid'     => array('sessionID' => 701, 'localID' => 2),
            'type'     => 'COMPONENT',
            'name'     => 'Styled image component',
            'children' => array(
                array(
                    'guid'           => array('sessionID' => 701, 'localID' => 3),
                    'type'           => 'RECTANGLE',
                    'name'           => 'Styled image',
                    'width'          => 100,
                    'height'         => 50,
                    'styleIdForFill' => array('guid' => array('sessionID' => 701, 'localID' => 1)),
                ),
            ),
        ),
        array(
            'id'         => 'instance:styled-preview',
            'type'       => 'INSTANCE',
            'name'       => 'Styled preview instance',
            'symbolData' => array(
                'symbolID' => array('sessionID' => 701, 'localID' => 2),
                'symbolOverrides' => array(
                    array(
                        'guidPath'   => array('guids' => array(array('sessionID' => 701, 'localID' => 3))),
                        'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'styled-override-image')),
                    ),
                ),
            ),
        ),
    ),
    'assets' => array(
        array('id' => 'styled-default-image', 'content' => 'styled default'),
        array('id' => 'styled-override-image', 'content' => 'styled override'),
    ),
));
$styleBackedImageOverrideCss = $fileContent($styleBackedImageOverrideResult, 'style.css');
$assert(str_contains($styleBackedImageOverrideCss, 'background-image:url("assets/styled-override-image.bin")'), 'style-backed-image-override-replaces-style-image-paint');
$assert(! str_contains($styleBackedImageOverrideCss, 'styled-default-image.bin'), 'style-backed-image-override-drops-stale-style-image-paint');

$lateNestedInstanceSourceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'        => 'Late Nested Instance Source Fixture',
    'nodeChanges' => array(
        array('guid' => array('sessionID' => 1, 'localID' => 1), 'type' => 'FRAME', 'name' => 'Page'),
        array('guid' => array('sessionID' => 10, 'localID' => 1), 'type' => 'COMPONENT', 'name' => 'Inner component'),
        array(
            'guid'        => array('sessionID' => 10, 'localID' => 2),
            'parentIndex' => array('guid' => array('sessionID' => 10, 'localID' => 1)),
            'type'        => 'TEXT',
            'name'        => 'Inner text',
            'characters'  => 'Nested source content',
        ),
        array('guid' => array('sessionID' => 20, 'localID' => 1), 'type' => 'COMPONENT', 'name' => 'Outer component'),
        array(
            'guid'        => array('sessionID' => 100, 'localID' => 1),
            'parentIndex' => array('guid' => array('sessionID' => 1, 'localID' => 1)),
            'type'        => 'INSTANCE',
            'name'        => 'Outer instance',
            'symbolData'  => array('symbolID' => array('sessionID' => 20, 'localID' => 1)),
        ),
        array(
            'guid'        => array('sessionID' => 200, 'localID' => 1),
            'parentIndex' => array('guid' => array('sessionID' => 20, 'localID' => 1)),
            'type'        => 'INSTANCE',
            'name'        => 'Inner slot',
            'symbolData'  => array('symbolID' => array('sessionID' => 10, 'localID' => 1)),
        ),
    ),
));
$lateNestedInstanceSourceHtml = $fileContent($lateNestedInstanceSourceResult, 'index.html');
$assert(str_contains($lateNestedInstanceSourceHtml, 'Nested source content'), 'late-nested-instance-source-renders-descendants');
$assert(str_contains($lateNestedInstanceSourceHtml, 'data-figma-node-id="100:1/200:1/10:2"'), 'late-nested-instance-source-preserves-namespaced-descendant-id');

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

$componentPropVisibilityNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$componentPropVisibilityNormalized = $componentPropVisibilityNormalizer->normalize(array(
    'name'  => 'Component Property Visibility Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:search-input',
            'type'     => 'COMPONENT',
            'name'     => 'Search input component',
            'key'      => 'search-input-key',
            'children' => array(
                array(
                    'id'      => 'component:search-input/icon-wrapper',
                    'type'    => 'FRAME',
                    'name'    => 'Search icon wrapper',
                    'visible' => false,
                    'componentPropRefs' => array(
                        array(
                            'defID'                  => array('sessionID' => 4169, 'localID' => 36),
                            'componentPropNodeField' => 'VISIBLE',
                        ),
                    ),
                    'children' => array(
                        array(
                            'id'      => 'component:search-input/icon',
                            'type'    => 'VECTOR',
                            'name'    => 'Search icon',
                            'visible' => false,
                            'width'   => 12,
                            'height'  => 12,
                            'componentPropRefs' => array(
                                array(
                                    'defID'                  => array('sessionID' => 4169, 'localID' => 36),
                                    'componentPropNodeField' => 'VISIBLE',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'          => 'instance:search-input',
            'type'        => 'INSTANCE',
            'name'        => 'Search input instance',
            'componentId' => 'search-input-key',
            'componentPropAssignments' => array(
                array(
                    'defID' => array('sessionID' => 4169, 'localID' => 36),
                    'value' => array('boolValue' => true),
                ),
            ),
        ),
    ),
));
$componentPropVisibilityInstance = $componentPropVisibilityNormalized['node_map']['instance:search-input'] ?? array();
$componentPropVisibilityWrapper = $componentPropVisibilityInstance['children'][0] ?? array();
$componentPropVisibilityIcon = is_array($componentPropVisibilityWrapper['children'][0] ?? null) ? $componentPropVisibilityWrapper['children'][0] : array();
$assert(true === ($componentPropVisibilityWrapper['visible'] ?? null), 'component-prop-visibility-shows-hidden-wrapper');
$assert(true === ($componentPropVisibilityIcon['visible'] ?? null), 'component-prop-visibility-shows-hidden-nested-icon');

$componentPropInstanceSwapNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$componentPropInstanceSwapNormalized = $componentPropInstanceSwapNormalizer->normalize(array(
    'name'  => 'Component Property Instance Swap Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:icon-default',
            'type'     => 'COMPONENT',
            'name'     => 'Default icon component',
            'key'      => 'default-icon-key',
            'children' => array(
                array('id' => 'component:icon-default/vector', 'type' => 'VECTOR', 'name' => 'Default icon vector', 'width' => 10, 'height' => 10, 'pathData' => 'M0 0H10V10Z'),
            ),
        ),
        array(
            'guid'     => array('sessionID' => 7001, 'localID' => 1),
            'type'     => 'COMPONENT',
            'name'     => 'Swapped icon component',
            'children' => array(
                array('guid' => array('sessionID' => 7001, 'localID' => 2), 'type' => 'TEXT', 'name' => 'Swapped icon label', 'characters' => 'Swapped icon'),
            ),
        ),
        array(
            'id'       => 'component:button-with-swap',
            'type'     => 'COMPONENT',
            'name'     => 'Button with swappable icon',
            'key'      => 'button-swap-key',
            'children' => array(
                array(
                    'id'          => 'component:button-with-swap/icon-slot',
                    'type'        => 'INSTANCE',
                    'name'        => 'Icon slot',
                    'componentId' => 'default-icon-key',
                    'width'       => 10,
                    'height'      => 10,
                    'componentPropRefs' => array(
                        array(
                            'defID'                  => array('sessionID' => 7001, 'localID' => 9),
                            'componentPropNodeField' => 'OVERRIDDEN_SYMBOL_ID',
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'          => 'instance:button-with-swap',
            'type'        => 'INSTANCE',
            'name'        => 'Button with swapped icon',
            'componentId' => 'button-swap-key',
            'componentPropAssignments' => array(
                array(
                    'defID' => array('sessionID' => 7001, 'localID' => 9),
                    'value' => array('guidValue' => array('sessionID' => 7001, 'localID' => 1)),
                ),
            ),
        ),
    ),
));
$componentPropInstanceSwap = $componentPropInstanceSwapNormalized['node_map']['instance:button-with-swap'] ?? array();
$componentPropInstanceSwapSlot = is_array($componentPropInstanceSwap['children'][0] ?? null) ? $componentPropInstanceSwap['children'][0] : array();
$componentPropInstanceSwapLabel = is_array($componentPropInstanceSwapSlot['children'][0] ?? null) ? $componentPropInstanceSwapSlot['children'][0] : array();
$assert('Swapped icon' === ($componentPropInstanceSwapLabel['characters'] ?? null), 'component-prop-instance-swap-replaces-default-component');
$assert('instance:button-with-swap/component:button-with-swap/icon-slot' === ($componentPropInstanceSwapSlot['id'] ?? null), 'component-prop-instance-swap-preserves-slot-id');

$nestedSymbolComponentPropNormalizer = new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer();
$nestedSymbolComponentPropNormalized = $nestedSymbolComponentPropNormalizer->normalize(array(
    'name'  => 'Nested Symbol Component Property Fixture',
    'nodes' => array(
        array(
            'id'       => 'component:nested-label',
            'type'     => 'COMPONENT',
            'name'     => 'Nested label source',
            'key'      => 'nested-label-key',
            'children' => array(
                array(
                    'id'         => 'component:nested-label/text',
                    'type'       => 'TEXT',
                    'name'       => 'Nested label text',
                    'characters' => 'Default nested label',
                    'componentPropRefs' => array(
                        array(
                            'defID'                  => array('sessionID' => 9002, 'localID' => 10),
                            'componentPropNodeField' => 'TEXT_DATA',
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'       => 'component:nested-shell',
            'type'     => 'COMPONENT',
            'name'     => 'Nested shell source',
            'key'      => 'nested-shell-key',
            'children' => array(
                array(
                    'guid'        => array('sessionID' => 9002, 'localID' => 20),
                    'type'        => 'INSTANCE',
                    'name'        => 'Nested label slot',
                    'componentId' => 'nested-label-key',
                ),
            ),
        ),
        array(
            'id'         => 'instance:nested-shell',
            'type'       => 'INSTANCE',
            'name'       => 'Nested shell instance',
            'componentId' => 'nested-shell-key',
            'symbolData' => array(
                'symbolOverrides' => array(
                    array(
                        'guidPath' => array('guids' => array(array('sessionID' => 9002, 'localID' => 20))),
                        'componentPropAssignments' => array(
                            array(
                                'defID' => array('sessionID' => 9002, 'localID' => 10),
                                'value' => array('textValue' => array('characters' => 'Assigned nested label')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$nestedSymbolComponentPropInstance = $nestedSymbolComponentPropNormalized['node_map']['instance:nested-shell'] ?? array();
$nestedSymbolComponentPropSlot = is_array($nestedSymbolComponentPropInstance['children'][0] ?? null) ? $nestedSymbolComponentPropInstance['children'][0] : array();
$nestedSymbolComponentPropText = is_array($nestedSymbolComponentPropSlot['children'][0] ?? null) ? $nestedSymbolComponentPropSlot['children'][0] : array();
$assert('Assigned nested label' === ($nestedSymbolComponentPropText['figma_text']['characters'] ?? null), 'nested-symbol-component-prop-assignment-applies-text');
$assert('instance:nested-shell/9002:20/component:nested-label/text' === ($nestedSymbolComponentPropText['id'] ?? null), 'nested-symbol-component-prop-assignment-preserves-namespaced-target');

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

$nestedScaledVectorInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Nested Scaled Vector Instance Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'id'       => 'nested-scaled-icon:source',
            'type'     => 'COMPONENT',
            'name'     => 'Nested scaled icon source',
            'key'      => 'nested-scaled-icon-source-key',
            'width'    => 80,
            'height'   => 64,
            'children' => array(
                array(
                    'id'           => 'nested-scaled-icon:vector',
                    'type'         => 'VECTOR',
                    'name'         => 'Vector',
                    'width'        => 80,
                    'height'       => 64,
                    'fillGeometry' => array(array('commandsBlob' => 0, 'windingRule' => 'NONZERO')),
                ),
            ),
        ),
        array(
            'id'       => 'nested-scaled-icon:wrapper',
            'type'     => 'COMPONENT',
            'name'     => 'Nested scaled icon wrapper',
            'key'      => 'nested-scaled-icon-wrapper-key',
            'width'    => 80,
            'height'   => 64,
            'layout'   => array('clips_content' => true),
            'children' => array(
                array(
                    'id'          => 'nested-scaled-icon:nested-instance',
                    'type'        => 'INSTANCE',
                    'name'        => 'Nested icon instance',
                    'componentId' => 'nested-scaled-icon-source-key',
                    'width'       => 80,
                    'height'      => 64,
                ),
            ),
        ),
        array(
            'id'          => 'nested-scaled-icon:instance',
            'type'        => 'INSTANCE',
            'name'        => 'Nested scaled icon instance',
            'componentId' => 'nested-scaled-icon-wrapper-key',
            'width'       => 40,
            'height'      => 32,
        ),
    ),
));
$nestedScaledVectorInstanceHtml = $fileContent($nestedScaledVectorInstanceResult, 'index.html');
$nestedScaledVectorInstanceCss = $fileContent($nestedScaledVectorInstanceResult, 'style.css');
$assert(str_contains($nestedScaledVectorInstanceHtml, 'data-figma-node-id="nested-scaled-icon:instance/nested-scaled-icon:nested-instance"') && str_contains($nestedScaledVectorInstanceCss, '.vector{width:40px;height:32px'), 'nested-scaled-vector-instance-child-css-scaled');
$assert(str_contains($nestedScaledVectorInstanceHtml, 'data-figma-node-id="nested-scaled-icon:instance/nested-scaled-icon:nested-instance/nested-scaled-icon:vector"') && str_contains($nestedScaledVectorInstanceHtml, 'viewBox="0 0 40 32"'), 'nested-scaled-vector-instance-descendant-css-scaled');
$assert(str_contains($nestedScaledVectorInstanceHtml, '<g transform="scale(0.5 0.5)">'), 'nested-scaled-vector-instance-svg-transform');

blocks_engine_figma_transformer_run_effects_contract($assert, $fileContent);

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

$derivedSymbolStructInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Derived Symbol Struct Overrides Fixture',
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 43, 'localID' => 1),
            'type'     => 'SYMBOL',
            'name'     => 'Derived Symbol Struct',
            'children' => array(
                array(
                    'guid'       => array('sessionID' => 43, 'localID' => 2),
                    'type'       => 'TEXT',
                    'name'       => 'Struct Label',
                    'characters' => 'Default Struct',
                    'width'      => 80,
                    'height'     => 20,
                ),
            ),
        ),
        array(
            'id'                => 'derived-struct:instance',
            'type'              => 'INSTANCE',
            'name'              => 'Derived Struct Instance',
            'symbolData'        => array(
                'symbolID' => array('sessionID' => 43, 'localID' => 1),
            ),
            'derivedSymbolData' => array(
                'symbolID' => array('guid' => array('sessionID' => 43, 'localID' => 1)),
                'symbolOverrides' => array(
                    array(
                        'guidPath' => array('guids' => array(array('sessionID' => 43, 'localID' => 2))),
                        'textData' => array('characters' => 'Struct override'),
                    ),
                ),
                'uniformScaleFactor' => 1.0,
            ),
        ),
    ),
));
$derivedSymbolStructInstanceHtml = $fileContent($derivedSymbolStructInstanceResult, 'index.html');
$assert(str_contains($derivedSymbolStructInstanceHtml, 'Struct override'), 'derived-symbol-struct-instance-text-override');
$assert(str_contains($derivedSymbolStructInstanceHtml, 'data-figma-node-id="derived-struct:instance/43:2"'), 'derived-symbol-struct-instance-label-namespaced');

$derivedSymbolPathOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Derived Symbol Path Override Fixture',
    'blobs' => array(array('bytes' => $vectorCommandBlob)),
    'nodes' => array(
        array(
            'guid'     => array('sessionID' => 42, 'localID' => 1),
            'type'     => 'SYMBOL',
            'name'     => 'Path Matched Symbol',
            'children' => array(
                array(
                    'id'       => 'left',
                    'type'     => 'FRAME',
                    'name'     => 'Left Branch',
                    'children' => array(
                        array(
                            'id'     => 'left/shared',
                            'type'   => 'VECTOR',
                            'name'   => 'Shared Leaf',
                            'width'  => 5,
                            'height' => 5,
                        ),
                    ),
                ),
                array(
                    'id'       => 'right',
                    'type'     => 'FRAME',
                    'name'     => 'Right Branch',
                    'children' => array(
                        array(
                            'id'     => 'right/shared',
                            'type'   => 'VECTOR',
                            'name'   => 'Shared Leaf',
                            'width'  => 5,
                            'height' => 5,
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'         => 'path-match:instance',
            'type'       => 'INSTANCE',
            'name'       => 'Path Matched Instance',
            'symbolData' => array(
                'symbolID' => array('sessionID' => 42, 'localID' => 1),
            ),
            'derivedSymbolData' => array(
                array(
                    'guidPath'       => array('guids' => array('left', 'shared')),
                    'size'           => array('x' => 10, 'y' => 10),
                    'transform'      => array('m00' => 1, 'm01' => 0, 'm02' => 7, 'm10' => 0, 'm11' => 1, 'm12' => 8),
                    'fillGeometry'   => array(array('commandsBlob' => 0)),
                    'strokeGeometry' => array(array('commandsBlob' => 0)),
                ),
            ),
        ),
    ),
));
$derivedSymbolPathOverrideHtml = $fileContent($derivedSymbolPathOverrideResult, 'index.html');
$derivedSymbolPathOverrideCss = $fileContent($derivedSymbolPathOverrideResult, 'style.css');
$assert(str_contains($derivedSymbolPathOverrideHtml, 'data-figma-node-id="path-match:instance/left/shared"'), 'derived-symbol-path-override-left-leaf-namespaced');
$assert(str_contains($derivedSymbolPathOverrideHtml, 'data-figma-node-id="path-match:instance/right/shared"'), 'derived-symbol-path-override-right-leaf-namespaced');
$assert(str_contains($derivedSymbolPathOverrideHtml, 'd="M0 0L10 0 10 10Z"'), 'derived-symbol-path-override-left-geometry');
$assert(str_contains($derivedSymbolPathOverrideCss, '.figma-node-path-match-instance-left-shared-shared-leaf{width:10px;height:10px;position:absolute;left:7px;top:8px'), 'derived-symbol-path-override-left-size-position');
$assert(str_contains($derivedSymbolPathOverrideCss, '.shared-leaf{width:5px;height:5px}') && ! str_contains($derivedSymbolPathOverrideCss, '.figma-node-path-match-instance-right-shared-shared-leaf{width:10px;height:10px'), 'derived-symbol-path-override-does-not-bleed-to-sibling-leaf');

$kiwiStackOverrideInstanceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Stack Override Fixture',
    'nodes' => array(
        array(
            'guid'      => array('sessionID' => 41, 'localID' => 1),
            'type'      => 'SYMBOL',
            'name'      => 'Kiwi Layout Symbol',
            'stackMode' => 'HORIZONTAL',
            'width'     => 400,
            'height'    => 120,
            'children'  => array(
                array(
                    'guid'   => array('sessionID' => 41, 'localID' => 2),
                    'type'   => 'FRAME',
                    'name'   => 'Override Card',
                    'stackMode' => 'HORIZONTAL',
                    'width'  => 80,
                    'height' => 40,
                ),
                array(
                    'guid'   => array('sessionID' => 41, 'localID' => 3),
                    'type'   => 'FRAME',
                    'name'   => 'Override Badge',
                    'width'  => 50,
                    'height' => 20,
                ),
            ),
        ),
        array(
            'id'         => 'kiwi-stack-override:instance',
            'type'       => 'INSTANCE',
            'name'       => 'Kiwi Stack Override Instance',
            'symbolData' => array(
                'symbolID' => array('sessionID' => 41, 'localID' => 1),
                'symbolOverrides' => array(
                    array(
                        'guidPath'              => array('guids' => array(array('sessionID' => 41, 'localID' => 2))),
                        'stackChildPrimaryGrow' => 1,
                        'stackChildAlignSelf'   => 'STRETCH',
                    ),
                    array(
                        'guidPath'         => array('guids' => array(array('sessionID' => 41, 'localID' => 3))),
                        'stackPositioning' => 'ABSOLUTE',
                        'transform'        => array('m00' => 1, 'm01' => 0, 'm02' => 24, 'm10' => 0, 'm11' => 1, 'm12' => 16),
                    ),
                ),
            ),
            'derivedSymbolData' => array(
                array(
                    'guidPath'            => array('guids' => array(array('sessionID' => 41, 'localID' => 2))),
                    'stackPrimarySizing'  => 'RESIZE_TO_FIT',
                    'stackCounterSizing'  => 'FIXED',
                    'stackChildAlignSelf' => 'STRETCH',
                ),
            ),
        ),
    ),
));
$kiwiStackOverrideInstanceCss = $fileContent($kiwiStackOverrideInstanceResult, 'style.css');
$kiwiStackOverrideResolverDiagnostics = array();
$kiwiStackOverrideResolverFields = ( new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\InstanceResolver() )->normalizeInstanceOverrides(array(
    'symbolData' => array(
        'symbolOverrides' => array(
            array(
                'guidPath'              => array('guids' => array(array('sessionID' => 41, 'localID' => 2))),
                'stackPrimarySizing'    => 'RESIZE_TO_FIT',
                'stackCounterSizing'    => 'FIXED',
                'stackChildPrimaryGrow' => 1,
            ),
        ),
    ),
    'derivedSymbolData' => array(
        array(
            'guidPath'            => array('guids' => array(array('sessionID' => 41, 'localID' => 2))),
            'stackPositioning'    => 'ABSOLUTE',
            'stackChildAlignSelf' => 'STRETCH',
        ),
    ),
), 'kiwi-stack-override:instance', $kiwiStackOverrideResolverDiagnostics);
$assert('RESIZE_TO_FIT' === ($kiwiStackOverrideResolverFields['41:2']['stackPrimarySizing'] ?? null), 'kiwi-stack-symbol-override-normalizes-primary-sizing');
$assert('FIXED' === ($kiwiStackOverrideResolverFields['41:2']['stackCounterSizing'] ?? null), 'kiwi-stack-symbol-override-normalizes-counter-sizing');
$assert(1 === ($kiwiStackOverrideResolverFields['41:2']['stackChildPrimaryGrow'] ?? null), 'kiwi-stack-symbol-override-normalizes-child-grow');
$assert('ABSOLUTE' === ($kiwiStackOverrideResolverFields['41:2']['stackPositioning'] ?? null), 'kiwi-stack-symbol-override-normalizes-positioning');
$assert('STRETCH' === ($kiwiStackOverrideResolverFields['41:2']['stackChildAlignSelf'] ?? null), 'kiwi-stack-symbol-override-normalizes-align-self');
$assert(str_contains($kiwiStackOverrideInstanceCss, '.figma-node-kiwi-stack-override-instance-41-2-override-card{width:80px;height:40px;position:absolute;display:flex;flex-direction:row;flex-grow:1;align-self:stretch}'), 'kiwi-stack-symbol-override-preserves-grow-align');
$assert(str_contains($kiwiStackOverrideInstanceCss, '.figma-node-kiwi-stack-override-instance-41-3-override-badge{width:50px;height:20px;position:absolute;left:24px;top:16px}'), 'kiwi-stack-symbol-override-preserves-positioning');

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

// Header-like logo wrappers often contain a single vector/boolean mark whose
// visual box is smaller than the wrapper. Keep that nested visual primitive
// positioned against the wrapper instead of letting normal flow pin it to 0,0.
$insetLogoVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Inset Logo Vector Fixture',
    'nodes' => array(
        array(
            'id'       => 'ilv:page',
            'type'     => 'FRAME',
            'name'     => 'Page',
            'width'    => 500,
            'height'   => 120,
            'children' => array(
                array(
                    'id'       => 'ilv:logo',
                    'type'     => 'INSTANCE',
                    'name'     => 'Logo',
                    'x'        => 20,
                    'y'        => 20,
                    'width'    => 228,
                    'height'   => 35,
                    'children' => array(
                        array(
                            'id'           => 'ilv:mark',
                            'type'         => 'BOOLEAN_OPERATION',
                            'name'         => 'Union',
                            'x'            => 0,
                            'y'            => 0,
                            'width'        => 227.682,
                            'height'       => 30,
                            'fillGeometry' => array(array('path' => 'M0 0L227.682 0L227.682 30L0 30Z', 'windingRule' => 'NONZERO')),
                            'fills'        => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$insetLogoVectorCss = $fileContent($insetLogoVectorResult, 'style.css');
$assert(str_contains($insetLogoVectorCss, '.figma-node-ilv-logo-logo{width:228px;height:35px;position:absolute;left:20px;top:20px'), 'inset-logo-wrapper-remains-positioned');
$assert(str_contains($insetLogoVectorCss, '.figma-node-ilv-mark-union{width:227.682px;height:30px;position:absolute;left:calc(50% - 113.841px);top:calc(50% - 15px)'), 'inset-logo-vector-centers-within-wrapper');

// Kiwi relativeTransformBounds carries parent-local layer geometry used by mixed
// logo/title components. If normalization drops those local x/y bounds, cloned
// instances emit every child at the implicit 0,0 position and the mark overlaps
// the leading title text in every header/footer/newsletter placement.
$kiwiLogoTitleCompositionResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Kiwi Logo Title Composition Fixture',
    'nodes' => array(
        array(
            'id'       => 'klt:logo-source',
            'type'     => 'COMPONENT',
            'name'     => 'Logo title source',
            'key'      => 'klt-logo-key',
            'width'    => 180,
            'height'   => 32,
            'children' => array(
                array(
                    'id'                      => 'klt:mark',
                    'type'                    => 'BOOLEAN_OPERATION',
                    'name'                    => 'Lego head mark',
                    'relativeTransformBounds' => array('x' => 0, 'y' => 4, 'width' => 24, 'height' => 24),
                    'fillGeometry'            => array(array('path' => 'M0 0L24 0L24 24L0 24Z', 'windingRule' => 'NONZERO')),
                    'fills'                   => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.84, 'b' => 0, 'a' => 1))),
                ),
                array(
                    'id'                      => 'klt:title',
                    'type'                    => 'TEXT',
                    'name'                    => 'The Baseplate title',
                    'characters'              => 'The Baseplate',
                    'relativeTransformBounds' => array('x' => 32, 'y' => 0, 'width' => 148, 'height' => 32),
                    'fontSize'                => 24,
                ),
            ),
        ),
        array('id' => 'klt:header-logo', 'type' => 'INSTANCE', 'name' => 'Header logo', 'componentId' => 'klt:logo-source', 'x' => 32, 'y' => 24, 'width' => 180, 'height' => 32),
        array('id' => 'klt:footer-logo', 'type' => 'INSTANCE', 'name' => 'Footer logo', 'componentId' => 'klt:logo-source', 'x' => 32, 'y' => 160, 'width' => 180, 'height' => 32),
        array('id' => 'klt:newsletter-logo', 'type' => 'INSTANCE', 'name' => 'Newsletter logo', 'componentId' => 'klt:logo-source', 'x' => 360, 'y' => 160, 'width' => 180, 'height' => 32),
    ),
));
$kiwiLogoTitleCompositionHtml = $fileContent($kiwiLogoTitleCompositionResult, 'index.html');
$kiwiLogoTitleCompositionCss = $fileContent($kiwiLogoTitleCompositionResult, 'style.css');
$assert(str_contains($kiwiLogoTitleCompositionCss, '.lego-head-mark{width:24px;height:24px;position:absolute;left:0px;top:4px'), 'kiwi-logo-title-mark-keeps-relative-bounds');
$assert(str_contains($kiwiLogoTitleCompositionCss, '.the-baseplate-title{width:148px;height:32px;position:absolute;left:32px;top:0px'), 'kiwi-logo-title-text-keeps-relative-bounds');
$assert(str_contains($kiwiLogoTitleCompositionHtml, 'data-figma-node-id="klt:header-logo/klt:title"'), 'kiwi-logo-title-header-instance-renders-title');
$assert(str_contains($kiwiLogoTitleCompositionHtml, 'data-figma-node-id="klt:footer-logo/klt:title"'), 'kiwi-logo-title-footer-instance-renders-title');
$assert(str_contains($kiwiLogoTitleCompositionHtml, 'data-figma-node-id="klt:newsletter-logo/klt:title"'), 'kiwi-logo-title-newsletter-instance-renders-title');

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

// Prototype links may target a descendant inside a planned page frame rather than the frame itself.
$descendantTargetScenegraph = array(
    'name'  => 'Descendant Prototype Target Fixture',
    'nodes' => array(
        array(
            'id'       => 'desc:home',
            'type'     => 'FRAME',
            'name'     => 'Home',
            'width'    => 1280,
            'height'   => 900,
            'children' => array(
                array(
                    'id'         => 'desc:cta',
                    'type'       => 'TEXT',
                    'name'       => 'About CTA',
                    'characters' => 'About',
                    'reactions'  => array(
                        array(
                            'action' => array('type' => 'NODE', 'navigation' => 'NAVIGATE', 'destinationId' => 'desc:about:title'),
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'       => 'desc:about',
            'type'     => 'FRAME',
            'name'     => 'About',
            'width'    => 1280,
            'height'   => 900,
            'children' => array(
                array('id' => 'desc:about:title', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About us'),
            ),
        ),
    ),
);
$descendantTargetResult = blocks_engine_figma_transformer_transform_scenegraph($descendantTargetScenegraph, array('include_all_pages' => true, 'entry_frame_id' => 'desc:home'));
$descendantTargetHomeHtml = $fileContent($descendantTargetResult, 'index.html');
$descendantTargetLinks = $descendantTargetResult['source_reports']['figma']['html']['transform_diagnostics']['links'] ?? array();
$assert(str_contains($descendantTargetHomeHtml, '<a class="figma-link" href="about.html#about-us" data-figma-link-type="node">'), 'descendant-prototype-target-resolves-to-containing-page');
$assert(0 === ($descendantTargetLinks['unresolved'] ?? null) && ($descendantTargetLinks['node_links'] ?? 0) >= 1, 'descendant-prototype-target-link-coverage-resolved');

// Real anchor tags: an unresolved NODE link is counted in the diagnostic without inventing href="#".
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
$assert(! str_contains($unresolvedHtml, 'href="#" data-figma-link-type="node"'), 'unresolved-node-link-does-not-emit-placeholder-anchor');
$assert(str_contains($unresolvedHtml, 'data-figma-node-id="dead:2"'), 'unresolved-node-link-preserves-source-element');
$assert(1 === ($unresolvedLinks['unresolved'] ?? null) && 1 === ($unresolvedLinks['node_links'] ?? null) && 0 === ($unresolvedLinks['anchors_emitted'] ?? null), 'unresolved-link-counted-in-coverage-without-anchor');
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
$assert(true === ($linkInteraction['actions'][0]['openUrlInNewTab'] ?? null), 'link-field-policy-carries-open-url-new-tab');
$assert('NEW_TAB' === ($linkInteraction['actions'][0]['urlTarget'] ?? null), 'link-field-policy-carries-url-target');
$assert('INTERNAL_NODE' === ($linkInteraction['actions'][1]['connectionType'] ?? null), 'link-field-policy-carries-node-connection-type');
$assert(array('sessionID' => 7, 'localID' => 42) === ($linkInteraction['actions'][1]['transitionNodeID'] ?? null), 'link-field-policy-carries-transition-node-guid');
$assert('OVERLAY' === ($linkInteraction['actions'][1]['navigationType'] ?? null), 'link-field-policy-carries-overlay-navigation-type');
$assert('CENTER' === ($linkInteraction['actions'][1]['overlayPositionType'] ?? null), 'link-field-policy-carries-overlay-position-type');
$assert(true === ($linkInteraction['actions'][1]['preserveScrollPosition'] ?? null), 'link-field-policy-carries-preserve-scroll-position');
$assert(true === ($linkInteraction['actions'][1]['resetScrollPosition'] ?? null), 'link-field-policy-carries-reset-scroll-position');

$prototypeMetadataNormalizer = new ScenegraphNormalizer();
$prototypeMetadataNormalized = $prototypeMetadataNormalizer->normalize(array(
    'name'  => 'Kiwi Prototype Metadata Fixture',
    'nodes' => array(
        array(
            'id'       => 'prototype-meta:home',
            'type'     => 'FRAME',
            'name'     => 'Home',
            'children' => array(
                array(
                    'id'                    => 'prototype-meta:overlay-trigger',
                    'type'                  => 'FRAME',
                    'name'                  => 'Open Overlay',
                    'prototypeInteractions' => array(
                        array(
                            'id'      => 'interaction:overlay',
                            'event'   => array('interactionType' => 'ON_CLICK'),
                            'actions' => array(
                                array(
                                    'connectionType'          => 'INTERNAL_NODE',
                                    'transitionNodeID'        => array('sessionID' => 9, 'localID' => 77),
                                    'navigationType'          => 'OVERLAY',
                                    'overlayPositionType'     => 'CENTER',
                                    'preserveScrollPosition'  => true,
                                    'overlayRelativePosition' => array('x' => 24, 'y' => 32),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$prototypeMetadataLink = $prototypeMetadataNormalized['node_map']['prototype-meta:overlay-trigger']['figma_link'] ?? array();
$assert('node' === ($prototypeMetadataLink['type'] ?? null), 'prototype-metadata-link-keeps-node-target');
$assert('9:77' === ($prototypeMetadataLink['target_node_id'] ?? null), 'prototype-metadata-link-normalizes-transition-guid');
$assert('OVERLAY' === ($prototypeMetadataLink['prototype_navigation_type'] ?? null), 'prototype-metadata-link-carries-navigation-type');
$assert('CENTER' === ($prototypeMetadataLink['prototype_overlay_position_type'] ?? null), 'prototype-metadata-link-carries-overlay-position-type');
$assert(true === ($prototypeMetadataLink['prototype_preserve_scroll_position'] ?? null), 'prototype-metadata-link-carries-preserve-scroll-position');
$assert(array('x' => 24, 'y' => 32) === ($prototypeMetadataLink['prototype_overlay_relative_position'] ?? null), 'prototype-metadata-link-carries-overlay-relative-position');
$assert('ON_CLICK' === ($prototypeMetadataLink['prototype_event'] ?? null), 'prototype-metadata-link-carries-event');
$assert('interaction:overlay' === ($prototypeMetadataLink['prototype_interaction_id'] ?? null), 'prototype-metadata-link-carries-interaction-id');

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
$assert('Selected label via variable' === ($componentPropAssignment['varValue']['value']['textDataValue']['characters'] ?? null), 'component-prop-field-policy-carries-var-value-text-data');
$assert(array('sessionID' => 9, 'localID' => 10) === ($componentPropRef['defID'] ?? null), 'component-prop-field-policy-carries-ref-def-id');
$assert('TEXT_DATA' === ($componentPropRef['componentPropNodeField'] ?? null), 'component-prop-field-policy-carries-text-ref-field');
$assert('raw' === ($componentPropNodeChange['pluginData'] ?? null), 'component-prop-field-policy-carries-adjacent-plugin-data');

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
$semanticCss = $fileContent($semanticElementsResult, 'style.css');
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
$assert(str_contains($semanticHtml, '<li class="figma-node-card-1-card-one'), 'semantic-repeated-item-emits-list-item');
$assert(str_contains($semanticCss, 'position:relative') && ! str_contains($semanticCss, 'display:list-item'), 'semantic-repeated-item-preserves-positioning-context');
$assert(! str_contains($semanticCss, '.figma-node-card-1-card-one::before'), 'semantic-repeated-card-list-avoids-implicit-marker-pseudo');
$assert(str_contains($semanticHtml, '<button class="figma-node-body-cta-get-started"'), 'semantic-button-like-node-emits-button');
$assert(! str_contains($semanticHtml, '<div class="figma-node-region-top-top-bar"'), 'semantic-header-not-generic-div');
// The middle content band (not a header/nav/footer landmark) is the genuine
// top-level region and reads as the page's single <section>; the deeply nested
// card/cta frames inside it do NOT each become their own <section>.
$assert(str_contains($semanticHtml, '<section class="figma-node-region-body-content"'), 'semantic-top-level-band-emits-section');
$assert(1 === substr_count($semanticHtml, '<section'), 'semantic-page-has-single-section');

$fluidParagraphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fluid Paragraph Fixture',
    'nodes' => array(
        array(
            'id'         => 'fluid-copy:page',
            'type'       => 'FRAME',
            'name'       => 'Article Page',
            'width'      => 1200,
            'height'     => 600,
            'layoutMode' => 'VERTICAL',
            'children'   => array(
                array(
                    'id'       => 'fluid-copy:paragraph',
                    'type'     => 'TEXT',
                    'name'     => 'Paragraph',
                    'width'    => 640,
                    'height'   => 116,
                    'fontSize' => 18,
                    'characters' => 'Responsive prose should keep source words intact and wrap in CSS instead of baking desktop soft line breaks.',
                    'figma_text' => array(
                        'characters'     => 'Responsive prose should keep source words intact and wrap in CSS instead of baking desktop soft line breaks.',
                        'derived_layout' => array(
                            'lines' => array(
                                array('start' => 0, 'end' => 27),
                                array('start' => 27, 'end' => 59),
                                array('start' => 59, 'end' => 101),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$fluidParagraphHtml = $fileContent($fluidParagraphResult, 'index.html');
$fluidParagraphCss = $fileContent($fluidParagraphResult, 'style.css');
$fluidParagraphRule = blocks_engine_figma_transformer_contract_css_rule($fluidParagraphCss, '.figma-node-fluid-copy-paragraph-paragraph');
$assert(str_contains($fluidParagraphRule, 'width:100%') && str_contains($fluidParagraphRule, 'max-width:640px') && ! str_contains($fluidParagraphRule, 'height:116px') && ! str_contains($fluidParagraphRule, 'white-space:'), 'fluid-paragraph-uses-intrinsic-max-width');
$assert(str_contains($fluidParagraphRule, 'flex-shrink:1') && str_contains($fluidParagraphRule, 'min-width:0'), 'fluid-paragraph-can-shrink-in-flex-flow');
$assert(str_contains($fluidParagraphHtml, 'source words intact') && ! str_contains($fluidParagraphHtml, "source\nwords"), 'fluid-paragraph-avoids-derived-soft-wrap-content');

$frameInspector = new ScenegraphFrameInspector();
$assert('6-blog' === $frameInspector->normalizedPageName('6_Blog_1440') && '6-blog' === $frameInspector->normalizedPageName('6_Blog_375'), 'responsive-name-normalizes-underscore-width-suffixes');

$centeredCanvasResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Centered Canvas Fixture',
    'nodes' => array(
        array(
            'id'       => 'centered:root',
            'type'     => 'FRAME',
            'name'     => 'Landing Page 1440',
            'width'    => 1440,
            'height'   => 900,
            'layoutMode' => 'VERTICAL',
            'counterAxisAlignItems' => 'CENTER',
            'children' => array(
                array(
                    'id'       => 'centered:band',
                    'type'     => 'FRAME',
                    'name'     => 'Hero Band',
                    'width'    => 1440,
                    'height'   => 480,
                    'children' => array(
                        array('id' => 'centered:heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Centered canvas', 'fontSize' => 56),
                    ),
                ),
            ),
        ),
    ),
));
$centeredCanvasHtml = $fileContent($centeredCanvasResult, 'index.html');
$centeredCanvasCss = $fileContent($centeredCanvasResult, 'style.css');
$centeredCanvasRule = blocks_engine_figma_transformer_contract_css_rule($centeredCanvasCss, '.figma-node-centered-root-landing-page-1440');
$assert(str_contains($centeredCanvasRule, 'width:100%') && ! str_contains($centeredCanvasRule, 'max-width:1440px') && ! str_contains($centeredCanvasRule, 'margin-left:auto') && ! str_contains($centeredCanvasRule, 'margin-right:auto'), 'centered-canvas-root-fills-viewport-without-implicit-canvas-cap');
$assert(! str_contains($centeredCanvasHtml, '<nav class="figma-node-centered-root-landing-page-1440"') && ! str_contains($centeredCanvasHtml, '<header class="figma-node-centered-root-landing-page-1440"') && ! str_contains($centeredCanvasHtml, '<footer class="figma-node-centered-root-landing-page-1440"'), 'centered-canvas-root-not-landmark');

$landmarkGuardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Landmark Guard Fixture',
    'nodes' => array(
        array(
            'id'       => 'landmark:root',
            'type'     => 'FRAME',
            'name'     => 'Marketing Page',
            'width'    => 1200,
            'height'   => 900,
            'children' => array(
                array(
                    'id'       => 'landmark:header',
                    'type'     => 'FRAME',
                    'name'     => 'Header',
                    'x'        => 0,
                    'y'        => 0,
                    'width'    => 1200,
                    'height'   => 96,
                    'children' => array(
                        array('id' => 'landmark:logo', 'type' => 'TEXT', 'name' => 'Logo', 'characters' => 'Agency', 'fontSize' => 24),
                        array('id' => 'landmark:about', 'type' => 'TEXT', 'name' => 'Menu Item', 'characters' => 'About', 'fontSize' => 16, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/about')),
                        array('id' => 'landmark:blog', 'type' => 'TEXT', 'name' => 'NewMenuItem', 'characters' => 'Blog', 'fontSize' => 16, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/blog')),
                    ),
                ),
                array(
                    'id'       => 'landmark:content-list',
                    'type'     => 'FRAME',
                    'name'     => 'Content List',
                    'x'        => 150,
                    'y'        => 220,
                    'width'    => 900,
                    'height'   => 300,
                    'children' => array(
                        array('id' => 'landmark:item-a', 'type' => 'TEXT', 'name' => 'List item one', 'characters' => 'Strategy', 'fontSize' => 24),
                        array('id' => 'landmark:item-b', 'type' => 'TEXT', 'name' => 'List item two', 'characters' => 'Design', 'fontSize' => 24),
                        array('id' => 'landmark:item-c', 'type' => 'TEXT', 'name' => 'List item three', 'characters' => 'Build', 'fontSize' => 24),
                    ),
                ),
                array(
                    'id'       => 'landmark:footer',
                    'type'     => 'FRAME',
                    'name'     => 'Footer Legal',
                    'x'        => 0,
                    'y'        => 780,
                    'width'    => 1200,
                    'height'   => 120,
                    'children' => array(
                        array('id' => 'landmark:legal', 'type' => 'TEXT', 'name' => 'Legal', 'characters' => 'All rights reserved.', 'fontSize' => 14),
                    ),
                ),
            ),
        ),
    ),
));
$landmarkGuardHtml = $fileContent($landmarkGuardResult, 'index.html');
$assert(str_contains($landmarkGuardHtml, '<header class="figma-node-landmark-header-header"'), 'landmark-explicit-header-still-header');
$assert(str_contains($landmarkGuardHtml, '<footer class="figma-node-landmark-footer-footer-legal"'), 'landmark-explicit-bottom-footer-still-footer');
$assert(! str_contains($landmarkGuardHtml, '<nav class="figma-node-landmark-about-menu-item"') && ! str_contains($landmarkGuardHtml, '<nav class="figma-node-landmark-blog-newmenuitem"'), 'landmark-menu-items-not-nav');
$assert(! str_contains($landmarkGuardHtml, '<footer class="figma-node-landmark-content-list-content-list"'), 'landmark-content-list-not-footer');

$linkedContentCardsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Linked Content Cards Fixture',
    'nodes' => array(
        array(
            'id'       => 'linked-cards:root',
            'type'     => 'FRAME',
            'name'     => 'Featured Content',
            'width'    => 1200,
            'height'   => 360,
            'children' => array(
                array('id' => 'linked-cards:item-1', 'type' => 'FRAME', 'name' => 'Story Preview', 'width' => 360, 'height' => 180, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/one'), 'children' => array(
                    array('id' => 'linked-cards:title-1', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'First story', 'fontSize' => 24),
                    array('id' => 'linked-cards:excerpt-1', 'type' => 'TEXT', 'name' => 'Excerpt', 'characters' => 'A short summary for the first story.', 'fontSize' => 16),
                )),
                array('id' => 'linked-cards:item-2', 'type' => 'FRAME', 'name' => 'Story Preview', 'width' => 360, 'height' => 180, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/two'), 'children' => array(
                    array('id' => 'linked-cards:title-2', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Second story', 'fontSize' => 24),
                    array('id' => 'linked-cards:excerpt-2', 'type' => 'TEXT', 'name' => 'Excerpt', 'characters' => 'A short summary for the second story.', 'fontSize' => 16),
                )),
                array('id' => 'linked-cards:item-3', 'type' => 'FRAME', 'name' => 'Story Preview', 'width' => 360, 'height' => 180, 'hyperlink' => array('type' => 'URL', 'url' => 'https://example.com/three'), 'children' => array(
                    array('id' => 'linked-cards:title-3', 'type' => 'TEXT', 'name' => 'Title', 'characters' => 'Third story', 'fontSize' => 24),
                    array('id' => 'linked-cards:excerpt-3', 'type' => 'TEXT', 'name' => 'Excerpt', 'characters' => 'A short summary for the third story.', 'fontSize' => 16),
                )),
            ),
        ),
    ),
));
$linkedContentCardsHtml = $fileContent($linkedContentCardsResult, 'index.html');
$assert(str_contains($linkedContentCardsHtml, '<ul class="figma-node-linked-cards-root-featured-content"'), 'semantic-linked-content-cards-emit-list');
$assert(3 === substr_count($linkedContentCardsHtml, '<li class="figma-node-linked-cards-item-'), 'semantic-linked-content-card-items');

$navMenuItemsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Navigation Menu Items Fixture',
    'nodes' => array(
        array(
            'id'       => 'nav-menu:page',
            'type'     => 'FRAME',
            'name'     => 'Page',
            'width'    => 900,
            'height'   => 240,
            'children' => array(
                array(
                    'id'       => 'nav-menu:nav',
                    'type'     => 'FRAME',
                    'name'     => 'Navigation',
                    'width'    => 520,
                    'height'   => 48,
                    'children' => array(
                        array('id' => 'nav-menu:item-1', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 90, 'height' => 26, 'children' => array(
                            array('id' => 'nav-menu:text-1', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'News', 'fontSize' => 24, 'fontWeight' => 700),
                        )),
                        array('id' => 'nav-menu:item-2', 'type' => 'FRAME', 'name' => 'Menu Item', 'width' => 110, 'height' => 26, 'children' => array(
                            array('id' => 'nav-menu:text-2', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Reviews', 'fontSize' => 24, 'fontWeight' => 700),
                        )),
                    ),
                ),
                array('id' => 'nav-menu:title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Actual Page Heading', 'fontSize' => 48, 'fontWeight' => 700),
                array('id' => 'nav-menu:copy', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Body copy establishes the page text scale.', 'fontSize' => 16),
            ),
        ),
    ),
));
$navMenuItemsHtml = $fileContent($navMenuItemsResult, 'index.html');
$assert(1 === substr_count($navMenuItemsHtml, '<nav class="figma-node-nav-menu-nav-navigation"'), 'semantic-nav-menu-items-single-nav-container');
$assert(! str_contains($navMenuItemsHtml, '<nav class="figma-node-nav-menu-item-1-menu-item"'), 'semantic-nav-menu-item-not-nested-nav');
$assert(str_contains($navMenuItemsHtml, '<div class="figma-node-nav-menu-item-1-menu-item"'), 'semantic-nav-menu-item-stays-structural');
$assert(str_contains($navMenuItemsHtml, '<span class="figma-node-nav-menu-text-1-text'), 'semantic-nav-label-text-inline');
$assert(! str_contains($navMenuItemsHtml, '<h2 class="figma-node-nav-menu-text-1-text"') && ! str_contains($navMenuItemsHtml, '<h3 class="figma-node-nav-menu-text-1-text"') && ! str_contains($navMenuItemsHtml, '<h4 class="figma-node-nav-menu-text-1-text"') && ! str_contains($navMenuItemsHtml, '<h5 class="figma-node-nav-menu-text-1-text"') && ! str_contains($navMenuItemsHtml, '<h6 class="figma-node-nav-menu-text-1-text"'), 'semantic-nav-label-text-not-heading');

$directTextListResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Direct Text List Fixture',
    'nodes' => array(
        array(
            'id'       => 'direct-list:root',
            'type'     => 'FRAME',
            'name'     => 'Footer Shell',
            'width'    => 600,
            'height'   => 120,
            'children' => array(
                array(
                    'id'       => 'direct-list:links',
                    'type'     => 'FRAME',
                    'name'     => 'Frame 29',
                    'width'    => 265,
                    'height'   => 26,
                    'children' => array(
                        array('id' => 'direct-list:about', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'About', 'width' => 48, 'height' => 26, 'fontSize' => 16),
                        array('id' => 'direct-list:contact', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Contact', 'width' => 64, 'height' => 26, 'fontSize' => 16),
                        array('id' => 'direct-list:privacy', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Privacy Policy', 'width' => 105, 'height' => 26, 'fontSize' => 16),
                    ),
                ),
            ),
        ),
    ),
));
$directTextListHtml = $fileContent($directTextListResult, 'index.html');
$assert(str_contains($directTextListHtml, '<ul class="figma-node-direct-list-links-frame-29"'), 'semantic-direct-text-list-container');
$assert(3 === substr_count($directTextListHtml, '<li class="figma-node-direct-list-'), 'semantic-direct-text-list-items');
$assert(! preg_match('/<ul class="figma-node-direct-list-links-frame-29"[\s\S]*<(p|h[1-6]) class="figma-node-direct-list-/', $directTextListHtml), 'semantic-direct-text-list-avoids-block-text-children');

$paginationControlResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Flexible Pagination Fixture',
    'nodes' => array(
        array(
            'id'       => 'pager:row',
            'type'     => 'FRAME',
            'name'     => 'Pagination',
            'width'    => 1216,
            'height'   => 40,
            'layoutMode'            => 'HORIZONTAL',
            'primaryAxisAlignItems'  => 'SPACE_BETWEEN',
            'counterAxisAlignItems'  => 'CENTER',
            'children' => array(
                array('id' => 'pager:prev', 'type' => 'FRAME', 'name' => 'Previous', 'width' => 462, 'height' => 20, 'layoutGrow' => 1, 'children' => array(
                    array('id' => 'pager:prev-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Previous', 'fontSize' => 16),
                )),
                array('id' => 'pager:numbers', 'type' => 'FRAME', 'name' => 'Pagination Numbers', 'width' => 292, 'height' => 40, 'layoutMode' => 'HORIZONTAL', 'children' => array(
                    array('id' => 'pager:n1', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '1', 'fontSize' => 16),
                    array('id' => 'pager:n2', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '2', 'fontSize' => 16),
                    array('id' => 'pager:ellipsis', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '...', 'fontSize' => 16),
                )),
                array('id' => 'pager:next', 'type' => 'FRAME', 'name' => 'Next', 'width' => 462, 'height' => 20, 'layoutGrow' => 1, 'children' => array(
                    array('id' => 'pager:next-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Next', 'fontSize' => 16),
                )),
            ),
        ),
    ),
));
$paginationControlHtml = $fileContent($paginationControlResult, 'index.html');
$paginationControlCss = $fileContent($paginationControlResult, 'style.css');
$assert(str_contains($paginationControlCss, 'width:auto;height:20px;flex-grow:1'), 'pagination-flex-control-width-auto');
$assert(! str_contains($paginationControlCss, 'width:462px'), 'pagination-flex-control-drops-fixed-edge-widths');
$assert(str_contains($paginationControlHtml, '<span class="figma-node-pager-ellipsis-number'), 'pagination-ellipsis-not-heading');

$freeformFlowResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Content Freeform Flow Fixture',
    'nodes' => array(
        array(
            'id'       => 'freeform-flow:root',
            'type'     => 'FRAME',
            'name'     => 'Featured Posts',
            'width'    => 800,
            'height'   => 360,
            'layout'   => array('freeform' => true),
            'children' => array(
                array('id' => 'freeform-flow:heading', 'type' => 'FRAME', 'name' => 'Heading With Separator', 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 48, 'children' => array(
                    array('id' => 'freeform-flow:title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Trending', 'fontSize' => 36),
                )),
                array('id' => 'freeform-flow:content', 'type' => 'FRAME', 'name' => 'Post Cards', 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 312, 'children' => array(
                    array('id' => 'freeform-flow:card-title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Post title', 'fontSize' => 24),
                )),
            ),
        ),
    ),
));
$freeformFlowCss = $fileContent($freeformFlowResult, 'style.css');
$assert(! preg_match('/\.figma-node-freeform-flow-heading-heading-with-separator\{[^}]*position:absolute/', $freeformFlowCss), 'content-freeform-heading-flows');
$assert(! preg_match('/\.figma-node-freeform-flow-content-post-cards\{[^}]*position:absolute/', $freeformFlowCss), 'content-freeform-content-flows');

$fluidClonedBandResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fluid Cloned Band Fixture',
    'nodes' => array(
        array(
            'id'       => 'fluid-band:page',
            'type'     => 'FRAME',
            'name'     => 'Page',
            'width'    => 1440,
            'height'   => 160,
            'children' => array(
                array(
                    'id'        => 'fluid-band:header',
                    'type'      => 'INSTANCE',
                    'name'      => 'Header',
                    'source_id' => 'component:header',
                    'width'     => 1440,
                    'height'    => 92,
                    'layout'    => array('freeform' => true),
                    'children'  => array(
                        array(
                            'id'       => 'fluid-band:nav-row',
                            'type'     => 'FRAME',
                            'name'     => 'Nav Row',
                            'x'        => 404,
                            'y'        => 24,
                            'width'    => 924,
                            'height'   => 44,
                            'layout'   => array('display' => 'flex', 'flex_direction' => 'row', 'grow' => 1),
                            'children' => array(
                                array('id' => 'fluid-band:nav', 'type' => 'FRAME', 'name' => 'Navigation', 'width' => 559, 'height' => 26, 'layout' => array('display' => 'flex', 'flex_direction' => 'row')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$fluidClonedBandCss = $fileContent($fluidClonedBandResult, 'style.css');
$assert(str_contains($fluidClonedBandCss, '.figma-node-fluid-band-header-header{width:100%;height:92px;position:relative'), 'fluid-cloned-freeform-band-renders-full-width');
$assert(str_contains($fluidClonedBandCss, '.figma-node-fluid-band-nav-row-nav-row{width:auto;height:44px;position:absolute;left:404px;right:112px'), 'fluid-absolute-grow-child-uses-left-right-gutters');

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

$variableBindingNormalizer = new ScenegraphNormalizer();
$variableBindingResult = $variableBindingNormalizer->normalize(array(
    'name'  => 'Variable Binding Fixture',
    'nodes' => array(
        array(
            'id'        => 'variable-node-gap',
            'type'      => 'VARIABLE',
            'name'      => 'Gap / Medium',
            'variableResolvedType' => 'FLOAT',
            'variableSetID' => array('guid' => array('sessionID' => 9, 'localID' => 1)),
            'variableScopes' => array('GAP'),
            'variableDataValues' => array(
                'entries' => array(
                    array(
                        'modeID' => array('sessionID' => 9, 'localID' => 2),
                        'variableData' => array(
                            'dataType' => 'FLOAT',
                            'resolvedDataType' => 'FLOAT',
                            'value' => array('floatValue' => 16.0),
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'        => 'variable-node-color',
            'type'      => 'VARIABLE',
            'name'      => 'Color / Brand',
            'variableResolvedType' => 'COLOR',
            'variableSetID' => array('guid' => array('sessionID' => 9, 'localID' => 1)),
            'variableScopes' => array('ALL_FILLS', 'STROKE'),
            'variableDataValues' => array(
                'entries' => array(
                    array(
                        'modeID' => array('sessionID' => 9, 'localID' => 2),
                        'variableData' => array(
                            'dataType' => 'COLOR',
                            'resolvedDataType' => 'COLOR',
                            'value' => array('colorValue' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3, 'a' => 1.0)),
                        ),
                    ),
                ),
            ),
        ),
        array(
            'id'        => 'variable-set-spacing',
            'type'      => 'VARIABLE_SET',
            'name'      => 'Spacing',
            'variableSetModes' => array(
                array(
                    'id' => array('sessionID' => 9, 'localID' => 2),
                    'name' => 'Desktop',
                    'sortPosition' => '!',
                    'parentVariableSetId' => array('guid' => array('sessionID' => 9, 'localID' => 1)),
                    'parentModeId' => array('sessionID' => 9, 'localID' => 2),
                ),
            ),
        ),
        array(
            'id'        => 'variable-frame',
            'type'      => 'FRAME',
            'name'      => 'Variable Frame',
            'width'     => 320,
            'height'    => 200,
            'stackMode' => 'VERTICAL',
            'stackSpacing' => 16,
            'boundVariables' => array(
                'itemSpacing' => array('type' => 'VARIABLE_ALIAS', 'id' => 'VariableID:9:3'),
                'fills' => array(array('type' => 'VARIABLE_ALIAS', 'id' => 'VariableID:9:4')),
                'characters' => array('guid' => array('sessionID' => 9, 'localID' => 5)),
            ),
            'variableConsumptionMap' => array(
                'entries' => array(
                    array(
                        'variableField' => 'STACK_SPACING',
                        'variableData' => array(
                            'dataType' => 'ALIAS',
                            'resolvedDataType' => 'FLOAT',
                            'value' => array('alias' => array('guid' => array('sessionID' => 9, 'localID' => 3))),
                        ),
                    ),
                ),
            ),
            'parameterConsumptionMap' => array(
                'entries' => array(
                    array(
                        'variableField' => 'TEXT_DATA',
                        'variableData' => array(
                            'dataType' => 'PROP_REF',
                            'resolvedDataType' => 'TEXT_DATA',
                            'value' => array('propRefValue' => array('defId' => array('sessionID' => 7, 'localID' => 4))),
                        ),
                    ),
                    array(
                        'nodeField' => 17,
                        'variableData' => array(
                            'dataType' => 'ALIAS',
                            'resolvedDataType' => 'FLOAT',
                            'value' => array('alias' => array('guid' => array('sessionID' => 9, 'localID' => 3))),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$variableFrameBindings = $variableBindingResult['node_map']['variable-frame']['figma_variable_bindings'] ?? array();
$assert(6 === ($variableFrameBindings['summary']['binding_count'] ?? null), 'variable-bindings-normalized-count');
$assert(2 === ($variableFrameBindings['summary']['by_role']['layout'] ?? null), 'variable-bindings-layout-role');
$assert(2 === ($variableFrameBindings['summary']['by_role']['text'] ?? null), 'variable-bindings-text-role');
$assert(1 === ($variableFrameBindings['summary']['by_role']['paint'] ?? null), 'variable-bindings-paint-role');
$assert(1 === ($variableFrameBindings['summary']['by_role']['unknown'] ?? null), 'variable-bindings-node-field-unknown-role');
$assert('9:3' === ($variableFrameBindings['bindings'][0]['variable_id'] ?? null), 'variable-bindings-alias-guid-normalized');
$assert('alias' === ($variableFrameBindings['bindings'][0]['value_type'] ?? null), 'variable-bindings-alias-value-type');
$assert('TEXT_DATA' === ($variableFrameBindings['bindings'][1]['variable_field'] ?? null), 'variable-bindings-variable-field-preserved');
$assert('prop_ref' === ($variableFrameBindings['bindings'][1]['value_type'] ?? null), 'variable-bindings-prop-ref-value-type');
$assert('7:4' === ($variableFrameBindings['bindings'][1]['prop_ref_id'] ?? null), 'variable-bindings-prop-ref-id-normalized');
$assert('17' === ($variableFrameBindings['bindings'][2]['node_field'] ?? null), 'variable-bindings-node-field-preserved');
$assert('nodeField:17' === ($variableFrameBindings['bindings'][2]['target_field'] ?? null), 'variable-bindings-node-field-target-normalized');
$assert('ITEM_SPACING' === ($variableFrameBindings['bindings'][3]['target_field'] ?? null), 'bound-variables-camel-target-normalized');
$assert('VariableID:9:3' === ($variableFrameBindings['bindings'][3]['variable_id'] ?? null), 'bound-variables-string-id-preserved');
$assert('--figma-var-variableid-9-3' === ($variableFrameBindings['bindings'][3]['css_custom_property'] ?? null), 'bound-variables-css-custom-property-candidate');
$assert('FILLS' === ($variableFrameBindings['bindings'][4]['target_field'] ?? null), 'bound-variables-list-target-normalized');
$assert('9:5' === ($variableFrameBindings['bindings'][5]['variable_id'] ?? null), 'bound-variables-guid-normalized');
$variableDefinition = $variableBindingResult['node_map']['variable-node-gap']['figma_variable_bindings'] ?? array();
$assert('FLOAT' === ($variableDefinition['resolved_type'] ?? null), 'variable-definition-resolved-type');
$assert(16.0 === ($variableDefinition['values'][0]['value'] ?? null), 'variable-definition-mode-value');
$assert('float' === ($variableDefinition['values'][0]['value_type'] ?? null), 'variable-definition-float-value-type');
$assert('9:1' === ($variableDefinition['variable_set_id'] ?? null), 'variable-definition-set-id-normalized');
$assert(array('GAP') === ($variableDefinition['scopes'] ?? null), 'variable-definition-scopes-normalized');
$variableColorDefinition = $variableBindingResult['node_map']['variable-node-color']['figma_variable_bindings'] ?? array();
$assert('color' === ($variableColorDefinition['values'][0]['value_type'] ?? null), 'variable-definition-color-value-type');
$assert(array('r' => 0.1, 'g' => 0.2, 'b' => 0.3, 'a' => 1.0) === ($variableColorDefinition['values'][0]['value'] ?? null), 'variable-definition-color-value-preserved');
$variableSetDefinition = $variableBindingResult['node_map']['variable-set-spacing']['figma_variable_bindings'] ?? array();
$assert('9:2' === ($variableSetDefinition['modes'][0]['id'] ?? null), 'variable-set-mode-id-normalized');
$assert('Desktop' === ($variableSetDefinition['modes'][0]['name'] ?? null), 'variable-set-mode-name-preserved');
$assert('9:1' === ($variableSetDefinition['modes'][0]['parent_variable_set_id'] ?? null), 'variable-set-mode-parent-set-id-normalized');
$assert('9:2' === ($variableSetDefinition['modes'][0]['parent_mode_id'] ?? null), 'variable-set-mode-parent-mode-id-normalized');
$variableSourceSummary = $variableBindingResult['source_report']['variable_bindings'] ?? array();
$assert(4 === ($variableSourceSummary['node_count'] ?? null), 'variable-source-summary-node-count');
$assert(6 === ($variableSourceSummary['binding_count'] ?? null), 'variable-source-summary-binding-count');
$assert(2 === ($variableSourceSummary['value_count'] ?? null), 'variable-source-summary-value-count');
$assert(2 === ($variableSourceSummary['variable_definition_count'] ?? null), 'variable-source-summary-definition-count');
$assert(1 === ($variableSourceSummary['variable_set_count'] ?? null), 'variable-source-summary-set-count');
$assert(5 === ($variableSourceSummary['by_value_type']['alias'] ?? null), 'variable-source-summary-alias-count');
$assert(1 === ($variableSourceSummary['by_value_type']['prop_ref'] ?? null), 'variable-source-summary-prop-ref-count');
$assert(1 === ($variableSourceSummary['by_value_type']['float'] ?? null), 'variable-source-summary-float-count');
$assert(1 === ($variableSourceSummary['by_value_type']['color'] ?? null), 'variable-source-summary-color-count');

$styleReferenceResult = $variableBindingNormalizer->normalize(array(
    'name' => 'Style Reference Fixture',
    'nodes' => array(
        array(
            'id' => 'paint-style:brand',
            'type' => 'RECTANGLE',
            'styleType' => 'FILL',
            'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.3, 'b' => 0.4))),
        ),
        array(
            'id' => 'text-style:headline',
            'type' => 'TEXT',
            'styleType' => 'TEXT',
            'characters' => 'Headline style',
            'fontFamily' => 'Inter',
            'fontSize' => 32,
        ),
        array(
            'id' => 'style-consumer',
            'type' => 'TEXT',
            'characters' => 'Styled',
            'styles' => array(
                'fill' => 'paint-style:brand',
                'text' => 'text-style:headline',
                'stroke' => 'missing-stroke-style',
                'effect' => 'missing-effect-style',
            ),
        ),
    ),
));
$styleConsumer = $styleReferenceResult['node_map']['style-consumer'] ?? array();
$assert('paint-style:brand' === ($styleConsumer['styleIdForFill'] ?? null), 'styles-map-fill-normalized-to-style-id-field');
$assert('text-style:headline' === ($styleConsumer['styleIdForText'] ?? null), 'styles-map-text-normalized-to-style-id-field');
$assert(array('r' => 0.2, 'g' => 0.3, 'b' => 0.4) === ($styleConsumer['figma_text']['style']['color'] ?? null), 'styles-map-fill-style-resolves-text-color');
$assert(32.0 === ($styleConsumer['figma_text']['style']['font_size'] ?? null), 'styles-map-text-style-resolves-typography');
$assert('missing-stroke-style' === ($styleConsumer['figma_style_references']['stroke'] ?? null), 'styles-map-stroke-reference-preserved');
$styleDiagnosticCodes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $styleReferenceResult['diagnostics'] ?? array());
$assert(in_array('figma_missing_paint_style_reference', $styleDiagnosticCodes, true), 'missing-paint-style-reference-diagnosed');
$assert(in_array('figma_missing_effect_style_reference', $styleDiagnosticCodes, true), 'missing-effect-style-reference-diagnosed');

blocks_engine_figma_transformer_run_fixture_matrix_contract($assert);
blocks_engine_figma_transformer_run_node_trace_contract($assert);
blocks_engine_figma_transformer_run_kiwi_skipped_field_inventory_contract($assert);
blocks_engine_figma_transformer_run_parser_parity_contract($assert);

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
$assert(str_contains($designSystemCss, '.type-heading-1,.figma-node-ds-type-heading-heading{'), 'design-system-heading-type-class');
$assert(str_contains($designSystemCss, '.type-body,.figma-node-ds-type-body-body{'), 'design-system-body-type-class');
$assert(str_contains($designSystemCss, 'font-size:var(--font-size-heading-1)'), 'design-system-type-class-references-token');
$assert(str_contains($designSystemCss, 'line-height:56px'), 'design-system-type-class-carries-line-height');
$assert(1 === preg_match('/\.figma-node-ds-type-heading-heading\{[^}]*font-size:var\(--font-size-heading-1\)/', $designSystemCss), 'design-system-node-css-materializes-heading-token');

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
$assert(2 === ($designSystemCoverage['materialized_type_nodes'] ?? 0), 'design-system-coverage-materialized-node-count');
$assert(in_array('figma-node-ds-type-heading-heading', $designSystemReport['materialized_node_classes'] ?? array(), true), 'design-system-report-materialized-node-class');
$assert(! empty($designSystemReport['type_token_map'] ?? array()), 'design-system-report-type-token-map');

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
$assert(null !== $designSystemDiagnostic && 2 === ($designSystemDiagnostic['materialized_type_nodes'] ?? 0), 'design-system-diagnostic-materialized-node-count');

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
$breakpointDimensionPolicy = new Automattic\BlocksEngine\FigmaTransformer\Html\BreakpointDimensionPolicy(fn (float $value): string => rtrim(rtrim(sprintf('%.4F', $value), '0'), '.'));
$responsiveNodeMatcher = new Automattic\BlocksEngine\FigmaTransformer\Html\ResponsiveNodeMatcher(fn (string $value): string => strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($value, '-')) ?? $value));
$assert('header' === $responsiveNodeMatcher->responsiveIdentity('Header Desktop 1440'), 'responsive-node-matcher-strips-desktop-width-qualifiers');
$assert('hero-card' === $responsiveNodeMatcher->responsiveIdentity('Hero Card / Mobile 390 x 844'), 'responsive-node-matcher-strips-mobile-viewport-size');
$responsiveNodeMatcherDesktopCounts = $responsiveNodeMatcher->siblingSignatureCounts(array(array('type' => 'FRAME', 'name' => 'Header Desktop')));
$responsiveNodeMatcherMobileCounts = $responsiveNodeMatcher->siblingSignatureCounts(array(array('type' => 'FRAME', 'name' => 'Header Mobile')));
$assert(
    $responsiveNodeMatcher->childKeys(array('type' => 'FRAME', 'name' => 'Header Desktop'), 0, $responsiveNodeMatcherDesktopCounts)
        === $responsiveNodeMatcher->childKeys(array('type' => 'FRAME', 'name' => 'Header Mobile'), 0, $responsiveNodeMatcherMobileCounts),
    'responsive-node-matcher-structural-keys-ignore-breakpoint-qualifiers'
);
$assert(
    array('reason_code' => 'root_fill', 'declarations' => array('width:100%')) === $breakpointDimensionPolicy->breakpointWidthDecision(
        '390px',
        array(),
        array('box' => array('width' => 1440)),
        array('type' => 'FRAME', 'box' => array('width' => 390)),
        null,
        null
    ),
    'breakpoint-dimension-policy-root-fill-decision-evidence'
);
$assert(
    array('width:100%', 'max-width:100%', 'height:auto', 'display:flex', 'flex-direction:column', 'align-items:stretch', 'justify-content:flex-start', 'min-height:96px') === $breakpointDimensionPolicy->headerChromeDeclarations(96.0),
    'breakpoint-dimension-policy-header-fluid-min-height-pairing'
);
$responsiveBreakpointSafetyPolicy = new Automattic\BlocksEngine\FigmaTransformer\Html\ResponsiveBreakpointSafetyPolicy(
    static fn (array $node): array => is_array($node['children'] ?? null) ? $node['children'] : array(),
    fn (float $value): string => rtrim(rtrim(sprintf('%.4F', $value), '0'), '.'),
    $breakpointDimensionPolicy,
    new Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier()
);
$assert(
    array('reason_code' => 'responsive_header_chrome_safety', 'declarations' => $breakpointDimensionPolicy->headerChromeDeclarations(96.0)) === $responsiveBreakpointSafetyPolicy->responsiveChromeFlowDecision(
        array('id' => 'policy:header', 'type' => 'FRAME', 'name' => 'Top Bar', 'box' => array('height' => 96)),
        null,
        array('height' => '96px'),
        null,
        'top bar',
        '',
        true,
        Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER,
        null
    ),
    'responsive-breakpoint-safety-policy-header-chrome-decision-seam'
);
$assert(
    array('reason_code' => '', 'declarations' => array()) === $responsiveBreakpointSafetyPolicy->responsiveChromeFlowDecision(
        array('id' => 'policy:header:cta', 'type' => 'INSTANCE', 'name' => 'Button One', 'box' => array('height' => 48)),
        array('id' => 'policy:header', 'type' => 'FRAME', 'name' => 'Header', 'box' => array('height' => 145)),
        array('position' => 'absolute', 'left' => '1180px', 'top' => '72px'),
        array('id' => 'policy:header-mobile:cta', 'type' => 'INSTANCE', 'name' => 'Button One', 'box' => array('height' => 48)),
        'button one',
        'header',
        true,
        null,
        Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER
    ),
    'responsive-breakpoint-safety-policy-header-child-preserves-matched-variant-geometry'
);
$assert(
    array('width:calc(100% - 48px)', 'max-width:342px', 'left:24px', 'right:auto') === $responsiveBreakpointSafetyPolicy->mobileCenteredTextFallbackDecision(
        array('id' => 'policy:text', 'type' => 'TEXT', 'name' => 'Hero Title'),
        array('id' => 'policy:parent', 'type' => 'FRAME'),
        array('left' => 'calc(50% - 360px)'),
        390.0,
        'TEXT',
        899.0,
        'absolute',
        null
    ),
    'responsive-breakpoint-safety-policy-centered-text-fallback-seam'
);
$assert(
    array('width:100%') === $breakpointDimensionPolicy->breakpointWidthDeclarations(
        '390px',
        array(),
        array('box' => array('width' => 1440)),
        array('type' => 'FRAME', 'box' => array('width' => 390)),
        null,
        null
    ),
    'breakpoint-dimension-policy-root-fills-viewport'
);
$assert(
    array('width:100%') === $breakpointDimensionPolicy->breakpointWidthDeclarations(
        '390px',
        array(),
        array('box' => array('width' => 1440)),
        array('type' => 'FRAME', 'box' => array('width' => 390)),
        array('box' => array('width' => 1440)),
        array('box' => array('width' => 390))
    ),
    'breakpoint-dimension-policy-parent-fill-uses-percent'
);
$assert(
    array('width:calc(100% - 48px)', 'max-width:1216px', 'margin-left:auto', 'margin-right:auto') === $breakpointDimensionPolicy->breakpointWidthDeclarations(
        '342px',
        array('display' => 'flex'),
        array('box' => array('width' => 1216)),
        array('type' => 'INSTANCE', 'box' => array('width' => 342)),
        array('box' => array('width' => 1440)),
        array('box' => array('width' => 390))
    ),
    'breakpoint-dimension-policy-source-max-centered-gutters'
);
$assert(
    array('width:calc(100% - 106px)', 'max-width:899px', 'left:53px', 'right:auto') === $breakpointDimensionPolicy->breakpointWidthDeclarations(
        '284px',
        array('position' => 'absolute'),
        array('box' => array('width' => 899)),
        array('type' => 'TEXT', 'box' => array('width' => 284)),
        array('box' => array('width' => 1440)),
        array('box' => array('width' => 390))
    ),
    'breakpoint-dimension-policy-absolute-source-max-centered-gutters'
);
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

$paginationSemanticsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Pagination Semantics Fixture',
    'nodes' => array(
        array(
            'id' => 'pag:root', 'type' => 'FRAME', 'name' => 'Root', 'width' => 1200, 'height' => 220,
            'layoutMode' => 'VERTICAL', 'itemSpacing' => 32,
            'children' => array(
                array(
                    'id' => 'pag:heading-row', 'type' => 'FRAME', 'name' => 'Heading with Separator', 'width' => 1200, 'height' => 48,
                    'layoutMode' => 'HORIZONTAL', 'counterAxisAlignItems' => 'CENTER', 'itemSpacing' => 24,
                    'children' => array(
                        array('id' => 'pag:heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Trending', 'width' => 169, 'height' => 48, 'fontSize' => 48, 'fontWeight' => 700, 'lineHeightPx' => 57.6),
                        array(
                            'id' => 'pag:separator-frame', 'type' => 'FRAME', 'name' => 'Frame 27', 'width' => 943, 'height' => 4,
                            'layoutMode' => 'HORIZONTAL', 'counterAxisAlignItems' => 'CENTER', 'paddingTop' => 4, 'paddingBottom' => 4,
                            'children' => array(
                                array('id' => 'pag:separator-vector', 'type' => 'VECTOR', 'name' => 'Vector 10', 'width' => 943, 'height' => 1),
                            ),
                        ),
                    ),
                ),
                array(
                    'id' => 'pag:controls', 'type' => 'FRAME', 'name' => 'Pagination', 'width' => 1216, 'height' => 40,
                    'layoutMode' => 'HORIZONTAL', 'primaryAxisAlignItems' => 'SPACE_BETWEEN', 'counterAxisAlignItems' => 'CENTER',
                    'children' => array(
                        array(
                            'id' => 'pag:previous', 'type' => 'FRAME', 'name' => 'Button', 'width' => 462, 'height' => 20,
                            'layoutMode' => 'HORIZONTAL',
                            'children' => array(
                                array(
                                    'id' => 'pag:previous-base', 'type' => 'FRAME', 'name' => '_Button base', 'width' => 100, 'height' => 20,
                                    'layoutMode' => 'HORIZONTAL', 'counterAxisAlignItems' => 'CENTER', 'primaryAxisAlignItems' => 'CENTER', 'itemSpacing' => 12,
                                    'children' => array(array('id' => 'pag:previous-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Previous', 'width' => 68, 'height' => 26, 'fontSize' => 16)),
                                ),
                            ),
                        ),
                        array(
                            'id' => 'pag:numbers', 'type' => 'FRAME', 'name' => 'Pagination numbers', 'width' => 292, 'height' => 40,
                            'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 2,
                            'children' => array(
                                array('id' => 'pag:n1', 'type' => 'FRAME', 'name' => '_Pagination number base', 'width' => 40, 'height' => 40, 'children' => array(array('id' => 'pag:t1', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '1'))),
                                array('id' => 'pag:n2', 'type' => 'FRAME', 'name' => '_Pagination number base', 'width' => 40, 'height' => 40, 'children' => array(array('id' => 'pag:t2', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '2'))),
                                array('id' => 'pag:n3', 'type' => 'FRAME', 'name' => '_Pagination number base', 'width' => 40, 'height' => 40, 'children' => array(array('id' => 'pag:t3', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '3'))),
                                array('id' => 'pag:n4', 'type' => 'FRAME', 'name' => '_Pagination number base', 'width' => 40, 'height' => 40, 'children' => array(array('id' => 'pag:t4', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '...'))),
                            ),
                        ),
                        array(
                            'id' => 'pag:next', 'type' => 'FRAME', 'name' => 'Button', 'width' => 462, 'height' => 20,
                            'layoutMode' => 'HORIZONTAL', 'primaryAxisAlignItems' => 'MAX',
                            'children' => array(
                                array(
                                    'id' => 'pag:next-base', 'type' => 'FRAME', 'name' => '_Button base', 'width' => 70, 'height' => 20,
                                    'layoutMode' => 'HORIZONTAL', 'counterAxisAlignItems' => 'CENTER', 'primaryAxisAlignItems' => 'CENTER', 'itemSpacing' => 12,
                                    'children' => array(array('id' => 'pag:next-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Next', 'width' => 38, 'height' => 26, 'fontSize' => 16)),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
$paginationSemanticsHtml = $fileContent($paginationSemanticsResult, 'index.html');
$paginationSemanticsCss = $fileContent($paginationSemanticsResult, 'style.css');
$assert('success' === ($paginationSemanticsResult['status'] ?? null), 'pagination-semantics-transform-success');
$assert(! preg_match('/<button[^>]*data-figma-node-id="pag:previous"[\s\S]*<button[^>]*data-figma-node-id="pag:previous-base"/', $paginationSemanticsHtml), 'pagination-previous-avoids-nested-button');
$assert(! preg_match('/<button[^>]*data-figma-node-id="pag:next"[\s\S]*<button[^>]*data-figma-node-id="pag:next-base"/', $paginationSemanticsHtml), 'pagination-next-avoids-nested-button');
$assert(str_contains($paginationSemanticsHtml, '<ul class="figma-node-pag-numbers-pagination-numbers"'), 'pagination-numbers-still-list');
$paginationNumbersRule = blocks_engine_figma_transformer_contract_css_rule($paginationSemanticsCss, '.figma-node-pag-numbers-pagination-numbers');
$paginationNumberBaseRule = blocks_engine_figma_transformer_contract_css_rule($paginationSemanticsCss, '.figma-node-pag-n1-pagination-number-base');
$assert(! str_contains($paginationNumbersRule, 'list-style:disc') && ! str_contains($paginationNumbersRule, 'padding-left:1.5em'), 'pagination-numbers-avoid-content-list-marker-css');
$assert(! str_contains($paginationNumberBaseRule, 'display:list-item') && ! str_contains($paginationSemanticsCss, '.figma-node-pag-n1-pagination-number-base::before'), 'pagination-number-base-avoids-content-list-marker-display');
$assert(str_contains($paginationSemanticsCss, '.figma-node-pag-separator-frame-frame-27{') && str_contains($paginationSemanticsCss, 'align-self:center') && str_contains($paginationSemanticsCss, 'margin-top:-4.8px'), 'heading-separator-line-box-offset');

$paginationActiveUnderlayResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emit(array(
    'schema' => 'blocks-engine/figma-transformer/scenegraph/v1',
    'nodes'  => array(
        array(
            'id' => 'active:content', 'type' => 'FRAME', 'name' => 'Content',
            'box' => array('width' => 40, 'height' => 40, 'coordinate_space' => 'local'),
            'layout' => array(
                'display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'center', 'align_items' => 'center',
            ),
            'children' => array(
                array(
                    'id' => 'active:ellipse', 'type' => 'ELLIPSE', 'name' => 'Ellipse',
                    'box' => array('x' => 2, 'y' => 2, 'width' => 36, 'height' => 36, 'coordinate_space' => 'local'),
                    'layout' => array('positioning' => 'absolute'),
                    'figma_paints' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1)))),
                ),
                array(
                    'id' => 'active:number', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '1',
                    'box' => array('x' => 16.5, 'y' => 14, 'width' => 7, 'height' => 12, 'coordinate_space' => 'local'),
                    'figma_text' => array('characters' => '1', 'style' => array('font_size' => 16, 'font_weight' => 700, 'color' => '#ffffff')),
                ),
            ),
        ),
    ),
));
$paginationActiveUnderlayCss = $fileContent($paginationActiveUnderlayResult, 'style.css');
$assert('success' === ($paginationActiveUnderlayResult['status'] ?? null), 'pagination-active-underlay-transform-success');
$assert(str_contains($paginationActiveUnderlayCss, '.figma-node-active-ellipse-ellipse{') && str_contains($paginationActiveUnderlayCss, 'z-index:1') && str_contains($paginationActiveUnderlayCss, 'pointer-events:none'), 'pagination-active-ellipse-underlay-z-index');
$assert(str_contains($paginationActiveUnderlayCss, '.figma-node-active-number-number{') && str_contains($paginationActiveUnderlayCss, 'z-index:2'), 'pagination-active-number-above-underlay');

$paginationResponsiveResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name' => 'Pagination Responsive Fixture',
        'nodes' => array(
            array(
                'id' => 'preserve:desktop', 'type' => 'FRAME', 'name' => 'Desktop', 'box' => array('width' => 1440, 'height' => 120),
                'children' => array(
                    array(
                        'id' => 'preserve:pagination', 'type' => 'FRAME', 'name' => 'Pagination', 'box' => array('width' => 1216, 'height' => 40),
                        'layout' => array('display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center'),
                        'children' => array(
                            array('id' => 'preserve:prev', 'type' => 'TEXT', 'name' => 'Previous', 'box' => array('width' => 68, 'height' => 26), 'figma_text' => array('characters' => 'Previous')),
                            array('id' => 'preserve:pages', 'type' => 'FRAME', 'name' => 'Pagination numbers', 'box' => array('width' => 292, 'height' => 40), 'layout' => array('display' => 'flex', 'flex_direction' => 'row'), 'children' => array(
                                array('id' => 'preserve:one', 'type' => 'TEXT', 'name' => 'Number', 'figma_text' => array('characters' => '1')),
                                array('id' => 'preserve:two', 'type' => 'TEXT', 'name' => 'Number', 'figma_text' => array('characters' => '2')),
                                array('id' => 'preserve:dots', 'type' => 'TEXT', 'name' => 'Number', 'figma_text' => array('characters' => '...')),
                            )),
                            array('id' => 'preserve:next', 'type' => 'TEXT', 'name' => 'Next', 'box' => array('width' => 38, 'height' => 26), 'figma_text' => array('characters' => 'Next')),
                        ),
                    ),
                ),
            ),
            array(
                'id' => 'preserve:mobile', 'type' => 'FRAME', 'name' => 'Mobile', 'box' => array('width' => 390, 'height' => 120),
                'children' => array(
                    array(
                        'id' => 'preserve:pagination-mobile', 'type' => 'FRAME', 'name' => 'Pagination', 'box' => array('width' => 342, 'height' => 36),
                        'layout' => array('display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center', 'flex_wrap' => 'wrap'),
                        'children' => array(
                            array('id' => 'preserve:prev-mobile', 'type' => 'TEXT', 'name' => 'Previous', 'box' => array('width' => 68, 'height' => 26), 'figma_text' => array('characters' => 'Previous')),
                            array('id' => 'preserve:pages-mobile', 'type' => 'FRAME', 'name' => 'Pagination numbers', 'box' => array('width' => 292, 'height' => 40), 'layout' => array('display' => 'flex', 'flex_direction' => 'row'), 'children' => array(
                                array('id' => 'preserve:one-mobile', 'type' => 'TEXT', 'name' => 'Number', 'figma_text' => array('characters' => '1')),
                                array('id' => 'preserve:two-mobile', 'type' => 'TEXT', 'name' => 'Number', 'figma_text' => array('characters' => '2')),
                                array('id' => 'preserve:dots-mobile', 'type' => 'TEXT', 'name' => 'Number', 'figma_text' => array('characters' => '...')),
                            )),
                            array('id' => 'preserve:next-mobile', 'type' => 'TEXT', 'name' => 'Next', 'box' => array('width' => 38, 'height' => 26), 'figma_text' => array('characters' => 'Next')),
                        ),
                    ),
                ),
            ),
        ),
    ),
    array('pages' => array(array('frame_id' => 'preserve:desktop', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
        array('frame_id' => 'preserve:desktop', 'viewport_width' => 1440.0, 'primary' => true),
        array('frame_id' => 'preserve:mobile', 'viewport_width' => 390.0, 'primary' => false),
    ))))
);
$paginationResponsiveCss = '';
foreach ( $paginationResponsiveResult['files'] ?? array() as $paginationResponsiveFile ) {
    if ( is_array($paginationResponsiveFile) && 'style.css' === ($paginationResponsiveFile['path'] ?? null) ) {
        $paginationResponsiveCss = (string) ($paginationResponsiveFile['content'] ?? '');
    }
}
$assert('success' === ($paginationResponsiveResult['status'] ?? null), 'pagination-responsive-transform-success');
$assert(str_contains($paginationResponsiveCss, '.figma-node-preserve-pagination-pagination{width:1216px;height:40px'), 'pagination-responsive-base-row-preserved');
$assert(! preg_match('/\.figma-node-preserve-pagination-pagination\{[^}]*height:36px/', $paginationResponsiveCss), 'pagination-responsive-does-not-override-height');
$assert(! preg_match('/\.figma-node-preserve-pagination-pagination\{[^}]*flex-wrap:wrap/', $paginationResponsiveCss), 'pagination-responsive-does-not-wrap');

$componentResponsiveStructureResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name' => 'Component Responsive Structure Fixture',
        'nodes' => array(
            array(
                'id' => 'struct:desktop', 'type' => 'FRAME', 'name' => 'Desktop', 'box' => array('width' => 1440, 'height' => 600),
                'children' => array(
                    array(
                        'id' => 'struct:section', 'type' => 'INSTANCE', 'name' => 'Feature section', 'source_id' => 'component:desktop-section', 'box' => array('width' => 1216, 'height' => 400),
                        'layout' => array('display' => 'flex', 'flex_direction' => 'row', 'gap' => 44),
                        'children' => array(
                            array('id' => 'struct:lead', 'type' => 'INSTANCE', 'name' => 'Lead card', 'source_id' => 'component:desktop-lead', 'box' => array('width' => 691, 'height' => 300)),
                            array('id' => 'struct:list', 'type' => 'INSTANCE', 'name' => 'Card list', 'source_id' => 'component:desktop-list', 'box' => array('width' => 481, 'height' => 300)),
                        ),
                    ),
                ),
            ),
            array(
                'id' => 'struct:mobile', 'type' => 'FRAME', 'name' => 'Mobile', 'box' => array('width' => 390, 'height' => 900),
                'children' => array(
                    array(
                        'id' => 'struct:section-mobile', 'type' => 'INSTANCE', 'name' => 'Feature section', 'source_id' => 'component:mobile-section', 'box' => array('width' => 342, 'height' => 820),
                        'layout' => array('display' => 'flex', 'flex_direction' => 'column', 'gap' => 32),
                        'children' => array(
                            array('id' => 'struct:lead-mobile', 'type' => 'INSTANCE', 'name' => 'Lead card', 'source_id' => 'component:mobile-lead', 'box' => array('width' => 342, 'height' => 420)),
                            array('id' => 'struct:list-mobile', 'type' => 'INSTANCE', 'name' => 'Card list', 'source_id' => 'component:mobile-list', 'box' => array('width' => 342, 'height' => 360)),
                        ),
                    ),
                ),
            ),
        ),
    ),
    array('pages' => array(array('frame_id' => 'struct:desktop', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
        array('frame_id' => 'struct:desktop', 'viewport_width' => 1440.0, 'primary' => true),
        array('frame_id' => 'struct:mobile', 'viewport_width' => 390.0, 'primary' => false),
    ))))
);
$componentResponsiveStructureCss = '';
foreach ( $componentResponsiveStructureResult['files'] ?? array() as $componentResponsiveStructureFile ) {
    if ( is_array($componentResponsiveStructureFile) && 'style.css' === ($componentResponsiveStructureFile['path'] ?? null) ) {
        $componentResponsiveStructureCss = (string) ($componentResponsiveStructureFile['content'] ?? '');
    }
}
$assert('success' === ($componentResponsiveStructureResult['status'] ?? null), 'component-responsive-structure-transform-success');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-struct-section-feature-section\{[^}]*width:calc\(100% - 48px\)[^}]*max-width:1216px[^}]*margin-left:auto[^}]*margin-right:auto[^}]*height:auto[^}]*flex-direction:column[^}]*gap:32px/s', $componentResponsiveStructureCss) === 1, 'component-responsive-section-fluid-column-centered');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-struct-lead-lead-card\{[^}]*width:100%[^}]*height:auto/s', $componentResponsiveStructureCss) === 1, 'component-responsive-structural-child-maps-across-source-ids');

$reorderedResponsiveStructureResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name' => 'Reordered Component Responsive Structure Fixture',
        'nodes' => array(
            array(
                'id' => 'reorder:desktop', 'type' => 'FRAME', 'name' => 'Desktop', 'box' => array('width' => 1440, 'height' => 600),
                'children' => array(
                    array(
                        'id' => 'reorder:section', 'type' => 'INSTANCE', 'name' => 'Feature section', 'source_id' => 'component:desktop-section', 'box' => array('width' => 1216, 'height' => 400),
                        'layout' => array('display' => 'flex', 'flex_direction' => 'row', 'gap' => 44),
                        'children' => array(
                            array('id' => 'reorder:lead', 'type' => 'INSTANCE', 'name' => 'Lead card', 'source_id' => 'component:desktop-lead', 'box' => array('width' => 691, 'height' => 300)),
                            array('id' => 'reorder:list', 'type' => 'INSTANCE', 'name' => 'Card list', 'source_id' => 'component:desktop-list', 'box' => array('width' => 481, 'height' => 300)),
                        ),
                    ),
                ),
            ),
            array(
                'id' => 'reorder:mobile', 'type' => 'FRAME', 'name' => 'Mobile', 'box' => array('width' => 390, 'height' => 900),
                'children' => array(
                    array(
                        'id' => 'reorder:section-mobile', 'type' => 'INSTANCE', 'name' => 'Feature section', 'source_id' => 'component:mobile-section', 'box' => array('width' => 342, 'height' => 820),
                        'layout' => array('display' => 'flex', 'flex_direction' => 'column', 'gap' => 32),
                        'children' => array(
                            array('id' => 'reorder:list-mobile', 'type' => 'INSTANCE', 'name' => 'Card list', 'source_id' => 'component:mobile-list', 'box' => array('width' => 342, 'height' => 360)),
                            array('id' => 'reorder:lead-mobile', 'type' => 'INSTANCE', 'name' => 'Lead card', 'source_id' => 'component:mobile-lead', 'box' => array('width' => 342, 'height' => 420)),
                        ),
                    ),
                ),
            ),
        ),
    ),
    array('pages' => array(array('frame_id' => 'reorder:desktop', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
        array('frame_id' => 'reorder:desktop', 'viewport_width' => 1440.0, 'primary' => true),
        array('frame_id' => 'reorder:mobile', 'viewport_width' => 390.0, 'primary' => false),
    ))))
);
$reorderedResponsiveStructureCss = '';
foreach ( $reorderedResponsiveStructureResult['files'] ?? array() as $reorderedResponsiveStructureFile ) {
    if ( is_array($reorderedResponsiveStructureFile) && 'style.css' === ($reorderedResponsiveStructureFile['path'] ?? null) ) {
        $reorderedResponsiveStructureCss = (string) ($reorderedResponsiveStructureFile['content'] ?? '');
    }
}
$assert('success' === ($reorderedResponsiveStructureResult['status'] ?? null), 'reordered-responsive-structure-transform-success');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-reorder-lead-lead-card\{[^}]*width:100%[^}]*height:auto/s', $reorderedResponsiveStructureCss) === 1, 'reordered-responsive-unique-structural-child-maps-across-source-ids');
$assert(! preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-reorder-lead-lead-card\{[^}]*height:360px/s', $reorderedResponsiveStructureCss), 'reordered-responsive-unique-structural-child-avoids-ordinal-mismatch');

$absoluteComponentResponsiveResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name' => 'Absolute Component Responsive Fixture',
        'nodes' => array(
            array(
                'id' => 'abs:desktop', 'type' => 'FRAME', 'name' => 'Desktop', 'box' => array('width' => 1440, 'height' => 600),
                'children' => array(
                    array('id' => 'abs:newsletter', 'type' => 'INSTANCE', 'name' => 'Newsletter signup', 'source_id' => 'component:newsletter-desktop', 'box' => array('x' => 112, 'y' => 0, 'width' => 1216, 'height' => 352), 'layout' => array('positioning' => 'absolute')),
                ),
            ),
            array(
                'id' => 'abs:mobile', 'type' => 'FRAME', 'name' => 'Mobile', 'box' => array('width' => 390, 'height' => 600),
                'children' => array(
                    array('id' => 'abs:newsletter-mobile', 'type' => 'INSTANCE', 'name' => 'Newsletter signup', 'source_id' => 'component:newsletter-mobile', 'box' => array('x' => 24, 'y' => 0, 'width' => 342, 'height' => 420), 'layout' => array('positioning' => 'absolute')),
                ),
            ),
        ),
    ),
    array('pages' => array(array('frame_id' => 'abs:desktop', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
        array('frame_id' => 'abs:desktop', 'viewport_width' => 1440.0, 'primary' => true),
        array('frame_id' => 'abs:mobile', 'viewport_width' => 390.0, 'primary' => false),
    ))))
);
$absoluteComponentResponsiveCss = '';
foreach ( $absoluteComponentResponsiveResult['files'] ?? array() as $absoluteComponentResponsiveFile ) {
    if ( is_array($absoluteComponentResponsiveFile) && 'style.css' === ($absoluteComponentResponsiveFile['path'] ?? null) ) {
        $absoluteComponentResponsiveCss = (string) ($absoluteComponentResponsiveFile['content'] ?? '');
    }
}
$assert('success' === ($absoluteComponentResponsiveResult['status'] ?? null), 'absolute-component-responsive-transform-success');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-abs-newsletter-newsletter-signup\{[^}]*width:calc\(100% - 48px\)[^}]*max-width:1216px[^}]*left:24px[^}]*right:auto[^}]*height:420px/s', $absoluteComponentResponsiveCss) === 1, 'absolute-component-responsive-width-follows-breakpoint');

$absoluteTextResponsiveResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name' => 'Absolute Text Responsive Fixture',
        'nodes' => array(
            array(
                'id' => 'abstext:desktop', 'type' => 'FRAME', 'name' => 'Desktop', 'box' => array('width' => 1440, 'height' => 600),
                'children' => array(
                    array('id' => 'abstext:copy', 'type' => 'TEXT', 'name' => 'Footer copy', 'box' => array('x' => 435, 'y' => 91, 'width' => 899, 'height' => 25), 'layout' => array('positioning' => 'absolute'), 'figma_text' => array('characters' => 'Footer copy')),
                ),
            ),
            array(
                'id' => 'abstext:mobile', 'type' => 'FRAME', 'name' => 'Mobile', 'box' => array('width' => 390, 'height' => 600),
                'children' => array(
                    array('id' => 'abstext:copy-mobile', 'type' => 'TEXT', 'name' => 'Footer copy', 'box' => array('x' => 175, 'y' => 113, 'width' => 284, 'height' => 288), 'layout' => array('positioning' => 'absolute'), 'figma_text' => array('characters' => 'Footer copy')),
                ),
            ),
        ),
    ),
    array('pages' => array(array('frame_id' => 'abstext:desktop', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
        array('frame_id' => 'abstext:desktop', 'viewport_width' => 1440.0, 'primary' => true),
        array('frame_id' => 'abstext:mobile', 'viewport_width' => 390.0, 'primary' => false),
    ))))
);
$absoluteTextResponsiveCss = '';
foreach ( $absoluteTextResponsiveResult['files'] ?? array() as $absoluteTextResponsiveFile ) {
    if ( is_array($absoluteTextResponsiveFile) && 'style.css' === ($absoluteTextResponsiveFile['path'] ?? null) ) {
        $absoluteTextResponsiveCss = (string) ($absoluteTextResponsiveFile['content'] ?? '');
    }
}
$assert('success' === ($absoluteTextResponsiveResult['status'] ?? null), 'absolute-text-responsive-transform-success');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-abstext-copy-footer-copy\{[^}]*width:calc\(100% - 106px\)[^}]*max-width:899px[^}]*left:53px[^}]*right:auto/s', $absoluteTextResponsiveCss) === 1, 'absolute-text-responsive-position-centered-gutter');
$assert(! preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-abstext-copy-footer-copy\{[^}]*left:175px/s', $absoluteTextResponsiveCss), 'absolute-text-responsive-suppresses-raw-variant-left');

$responsiveSafetyResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(
    array(
        'name' => 'Responsive Safety Fixture',
        'nodes' => array(
            array(
                'id' => 'safety:desktop', 'type' => 'FRAME', 'name' => 'Desktop', 'box' => array('width' => 1440, 'height' => 900),
                'children' => array(
                    array(
                        'id' => 'safety:header', 'type' => 'FRAME', 'name' => 'Header', 'box' => array('width' => 1440, 'height' => 92),
                        'children' => array(
                            array('id' => 'safety:logo', 'type' => 'FRAME', 'name' => 'Logo', 'box' => array('x' => 112, 'y' => 28, 'width' => 228, 'height' => 35), 'layout' => array('positioning' => 'absolute')),
                            array(
                                'id' => 'safety:header-actions', 'type' => 'FRAME', 'name' => 'Frame 21', 'box' => array('x' => 404, 'y' => 24, 'width' => 924, 'height' => 44),
                                'layout' => array('positioning' => 'absolute', 'display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'flex-end', 'align_items' => 'baseline', 'gap' => 48),
                                'children' => array(
                                    array('id' => 'safety:nav', 'type' => 'FRAME', 'name' => 'Navigation', 'box' => array('width' => 559, 'height' => 26), 'layout' => array('display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'flex-end', 'align_items' => 'center', 'gap' => 32)),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id' => 'safety:footer', 'type' => 'FRAME', 'name' => 'Footer', 'box' => array('width' => 1440, 'height' => 483),
                        'children' => array(
                            array('id' => 'safety:newsletter', 'type' => 'INSTANCE', 'name' => 'Newsletter signup', 'source_id' => 'component:newsletter-desktop', 'box' => array('x' => 112, 'y' => 0, 'width' => 1216, 'height' => 352), 'layout' => array('positioning' => 'absolute')),
                            array('id' => 'safety:footer-row', 'type' => 'FRAME', 'name' => 'Frame 19', 'box' => array('x' => 0, 'y' => 352, 'width' => 1440, 'height' => 131), 'layout' => array('positioning' => 'absolute', 'display' => 'flex', 'flex_direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center')),
                        ),
                    ),
                ),
            ),
            array(
                'id' => 'safety:mobile', 'type' => 'FRAME', 'name' => 'Mobile', 'box' => array('width' => 390, 'height' => 900),
                'children' => array(
                    array('id' => 'safety:mobile-shell', 'type' => 'FRAME', 'name' => 'Mobile-only shell', 'box' => array('width' => 342, 'height' => 900)),
                ),
            ),
        ),
    ),
    array('pages' => array(array('frame_id' => 'safety:desktop', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true, 'variants' => array(
        array('frame_id' => 'safety:desktop', 'viewport_width' => 1440.0, 'primary' => true),
        array('frame_id' => 'safety:mobile', 'viewport_width' => 390.0, 'primary' => false),
    ))))
);
$responsiveSafetyCss = '';
foreach ( $responsiveSafetyResult['files'] ?? array() as $responsiveSafetyFile ) {
    if ( is_array($responsiveSafetyFile) && 'style.css' === ($responsiveSafetyFile['path'] ?? null) ) {
        $responsiveSafetyCss = (string) ($responsiveSafetyFile['content'] ?? '');
    }
}
$assert('success' === ($responsiveSafetyResult['status'] ?? null), 'responsive-safety-transform-success');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-safety-header-actions-frame-21\{[^}]*width:100%[^}]*position:relative[^}]*left:auto[^}]*right:auto[^}]*top:auto[^}]*flex-wrap:wrap/s', $responsiveSafetyCss) === 1, 'responsive-safety-header-actions-defixed');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-safety-nav-navigation\{[^}]*width:100%[^}]*max-width:100%[^}]*flex-wrap:wrap/s', $responsiveSafetyCss) === 1, 'responsive-safety-navigation-wraps');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-safety-newsletter-newsletter-signup\{[^}]*width:calc\(100% - 48px\)[^}]*max-width:1216px[^}]*left:24px/s', $responsiveSafetyCss) === 1, 'responsive-safety-newsletter-defixed');
$assert(preg_match('/@media \(max-width:915px\)\{[\s\S]*\.figma-node-safety-footer-row-frame-19\{[^}]*position:relative[^}]*left:auto[^}]*top:auto[^}]*flex-wrap:wrap/s', $responsiveSafetyCss) === 1, 'responsive-safety-footer-row-defixed');

if ( ! empty($failures) ) {
    fwrite(STDERR, "Figma Transformer contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Figma Transformer contract tests passed.\n");

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

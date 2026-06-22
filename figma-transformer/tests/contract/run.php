<?php

declare(strict_types=1);

require_once __DIR__ . '/../../figma-transformer.php';

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiParser;
use Automattic\BlocksEngine\FigmaTransformer\Parity\ParityReportBuilder;

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$scenegraph = array(
    'name'   => 'Fixture Site',
    'assets' => array(
        'hero-image' => array(
            'name'      => 'Hero Image',
            'mime_type' => 'image/svg+xml',
            'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
        ),
        'remote-image' => array(
            'url' => 'https://cdn.example.com/remote.png',
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

$html = $fileContent($result, 'index.html');
$css = $fileContent($result, 'style.css');
$diagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $result['diagnostics'] ?? array()
);

$assert('blocks-engine/figma-transformer/result/v1' === ($result['schema'] ?? null), 'result-schema');
$assert('success' === ($result['status'] ?? null), 'scenegraph-transform-success');
$assert(4 === ($result['metrics']['node_count'] ?? null), 'node-count');
$assert(1 === ($result['metrics']['asset_count'] ?? null), 'asset-count');
$assert(str_contains($html, 'Hello Figma'), 'html-contains-text');
$assert(str_contains($html, '<section class="figma-node-1-1-hero-section"'), 'frame-emits-section');
$assert(str_contains($html, '<h2 class="figma-node-1-2-hero-title"'), 'title-emits-heading');
$assert(str_contains($html, '<div class="figma-node-1-3-cards-group"'), 'group-emits-div');
$assert(! str_contains($html, '<FRAME') && ! str_contains($html, '<GROUP') && ! str_contains($html, '<TEXT') && ! str_contains($html, '<RECTANGLE'), 'html-avoids-custom-tags');
$assert(! str_contains($html, 'cdn.example.com') && ! str_contains($css, 'cdn.example.com'), 'html-css-avoid-external-cdn');
$assert(str_contains($css, '.figma-node-1-1-hero-section{width:1200px;height:600px;background:#ffffff;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;padding-top:40px;padding-right:32px;padding-bottom:40px;padding-left:32px;gap:24px}'), 'css-frame-layout-style');
$assert(str_contains($css, '.figma-node-1-2-hero-title{font-size:48px;font-weight:700;color:#1a334d}'), 'css-text-style');
$assert(str_contains($css, '.figma-node-1-4-hero-image-rectangle{width:320px;height:180px;position:absolute;left:10px;top:20px;background:#ff0000;background-image:url("assets/hero-image.svg")'), 'css-rectangle-asset-style');
$assert(! str_contains($css, 'font-family:Inter') && ! str_contains($css, 'body{margin:0;background') && ! str_contains($css, 'body{margin:0;color'), 'css-avoids-hardcoded-theme-style');
$assert('assets/hero-image.svg' === ($result['assets'][0]['path'] ?? null), 'asset-report-path');
$assert(in_array('external_asset_omitted', $diagnosticCodes, true), 'external-asset-diagnostic');
$assert(in_array('scenegraph_node_id_duplicate', $diagnosticCodes, true), 'duplicate-node-diagnostic');
$assert(($result['files'] ?? array()) === ($sameResult['files'] ?? array()), 'deterministic-files');
$assert('blocks-engine/figma-transformer/parity-report/v1' === ($result['parity']['schema'] ?? null), 'parity-schema');
$assert('not_run' === ($result['parity']['status'] ?? null), 'parity-default-not-run');

$parityBuilder = new ParityReportBuilder();
$pendingParity = $parityBuilder->build(array(
    'status'    => 'pending',
    'reason'    => 'queued_for_browser_runner',
    'artifacts' => array(
        'report_path' => 'artifacts/parity-report.json',
    ),
));
$comparedParity = $parityBuilder->build(array(
    'status'    => 'compared',
    'artifacts' => array(
        'source_screenshot_path'    => 'artifacts/source.png',
        'generated_screenshot_path' => 'artifacts/generated.png',
        'diff_image_path'           => 'artifacts/diff.png',
    ),
    'source' => array(
        'screenshot_path' => 'artifacts/source.png',
    ),
    'generated' => array(
        'screenshot_path' => 'artifacts/generated.png',
    ),
    'diff_summary' => array(
        'changed_pixels' => 42,
        'threshold'      => 0.02,
    ),
    'metrics' => array(
        'pixel_diff_ratio' => 0.01,
    ),
));
$assert('pending' === ($pendingParity['status'] ?? null), 'parity-pending-status');
$assert('artifacts/parity-report.json' === ($pendingParity['artifacts']['report_path'] ?? null), 'parity-pending-artifact-path');
$assert('compared' === ($comparedParity['status'] ?? null), 'parity-compared-status');
$assert('artifacts/source.png' === ($comparedParity['source']['screenshot_path'] ?? null), 'parity-source-screenshot-path');
$assert('artifacts/generated.png' === ($comparedParity['generated']['screenshot_path'] ?? null), 'parity-generated-screenshot-path');
$assert(42 === ($comparedParity['diff_summary']['changed_pixels'] ?? null), 'parity-diff-summary');
$assert(0.01 === ($comparedParity['metrics']['pixel_diff_ratio'] ?? null), 'parity-metric');

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
$assert(4 === count($chunks), 'fig-kiwi-chunk-count');
$assert('zlib' === ($chunks[0]['compression'] ?? null), 'fig-kiwi-first-chunk-zlib');
$assert('json' === ($chunks[0]['payload']['classification'] ?? null), 'fig-kiwi-first-chunk-json');
$assert(isset($chunks[0]['payload']['json']['NODE_CHANGES']), 'fig-kiwi-first-chunk-node-changes');
$assert('json_invalid' === ($chunks[1]['payload']['classification'] ?? null), 'fig-kiwi-second-chunk-json-invalid');
$assert('binary' === ($chunks[2]['payload']['classification'] ?? null), 'fig-kiwi-third-chunk-binary');
$assert('zstd' === ($chunks[3]['compression'] ?? null), 'fig-kiwi-fourth-chunk-zstd');
$assert(in_array($zstdCapabilityCode, $diagnosticCodes, true), 'fig-kiwi-zstd-capability-diagnostic');
$assert(is_bool($zstdStatus['available'] ?? null), 'zstd-status-available-bool');
$assert(is_bool($zstdStatus['extension_loaded'] ?? null), 'zstd-status-extension-loaded-bool');
$assert(is_array($zstdStatus['functions'] ?? null), 'zstd-status-functions-array');
$assert(array_key_exists('zstd_uncompress', $zstdStatus['functions'] ?? array()), 'zstd-status-uncompress-function');
$assert(($zstdStatus['available'] ?? null) === (($zstdStatus['extension_loaded'] ?? null) && ($zstdStatus['functions']['zstd_uncompress'] ?? null)), 'zstd-status-available-matches-runtime');
$assert(($zstdStatus['available'] ?? null) === ($zstdDiagnostic['context']['available'] ?? null), 'fig-kiwi-zstd-diagnostic-availability-context');
if ( true === ($zstdStatus['available'] ?? false) && function_exists('zstd_compress') ) {
    $zstdCompressed = zstd_compress('contract zstd round trip');
    $zstdRoundTrip = false !== $zstdCompressed ? $zstdCapability->uncompress($zstdCompressed, 'ContractTest', 1) : array('data' => null, 'diagnostics' => array());
    $assert('contract zstd round trip' === ($zstdRoundTrip['data'] ?? null), 'zstd-real-round-trip');
    $assert(isset($chunks[3]['inflated_bytes']), 'fig-kiwi-zstd-real-fixture-inflated');
} else {
    $zstdUnavailable = $zstdCapability->uncompress("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame', 'ContractTest', 1);
    $assert(null === ($zstdUnavailable['data'] ?? null), 'zstd-unavailable-returns-null');
    $assert(in_array((string) ($zstdUnavailable['diagnostics'][0]['code'] ?? ''), array('figma_transformer_zstd_extension_missing', 'figma_transformer_zstd_function_missing'), true), 'zstd-unavailable-diagnostic-code');
}
$assert(! empty($fileResult['files']), 'file-transform-renders-decoded-scenegraph');
$assert(4 === ($fileResult['metrics']['node_count'] ?? null), 'file-transform-node-count');
$assert(isset($fileResult['source_reports']['figma']['html']), 'file-transform-html-source-report');
$assert('synthetic' === ($fileResult['source_reports']['figma']['assets'][0]['id'] ?? null), 'archive-asset-id');
$assert('images/synthetic' === ($fileResult['source_reports']['figma']['assets'][0]['path'] ?? null), 'archive-asset-path');
$assert('asset' === ($fileResult['source_reports']['figma']['assets'][0]['content'] ?? null), 'archive-asset-content');

$wirePayload = blocks_engine_figma_transformer_wire_varint(8)
    . blocks_engine_figma_transformer_wire_varint(150)
    . blocks_engine_figma_transformer_wire_varint(18)
    . blocks_engine_figma_transformer_wire_varint(5)
    . 'hello'
    . blocks_engine_figma_transformer_wire_varint(29)
    . "\x01\x02\x03\x04";
$wireCanvasResult = ( new FigKiwiParser() )->parse(
    'fig-kiwi'
    . pack('V', 106)
    . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate($wirePayload))
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
            ),
        ),
    ),
));

$metadataHtml = $fileContent($metadataResult, 'index.html');
$metadataCss = $fileContent($metadataResult, 'style.css');
$metadataDiagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    $metadataResult['diagnostics'] ?? array()
);

$assert(str_contains($metadataHtml, '<span style="font-weight:400">Hello </span><span style="font-weight:700;text-decoration:underline">World</span>'), 'styled-text-segments-emit');
$assert(str_contains($metadataCss, '.figma-node-4-1-metadata-frame{background:rgba(51,102,153,0.5);opacity:0.75;border-radius:12px;border:2px solid #000000}'), 'normalized-frame-paint-box-style');
$assert(str_contains($metadataCss, '.figma-node-4-2-mixed-text{font-family:"Example Sans";font-size:20px;font-weight:600;line-height:125%;letter-spacing:0.5px;color:rgba(255,128,0,0.8);text-align:center;vertical-align:top;text-decoration:underline}'), 'normalized-text-style');
$assert(str_contains($metadataCss, '.figma-node-4-3-uneven-radius{border-top-left-radius:4px;border-top-right-radius:8px;border-bottom-right-radius:12px;border-bottom-left-radius:16px}'), 'individual-radius-style');
$assert(in_array('unsupported_figma_paint_type', $metadataDiagnosticCodes, true), 'unsupported-paint-diagnostic');
$assert(in_array('unsupported_figma_effect_type', $metadataDiagnosticCodes, true), 'unsupported-effect-diagnostic');

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

$assert(1 === ($assetReferenceResult['metrics']['asset_reference_count'] ?? null), 'normalized-image-reference-count');
$assert('imageHash' === ($assetReferenceReport['asset_references'][0]['source_key'] ?? null), 'normalized-image-reference-source-key');
$assert('image-hash-1' === ($assetReferenceReport['asset_references'][0]['ref'] ?? null), 'normalized-image-reference-ref');
$assert(str_contains($assetReferenceCss, 'background-image:url("assets/archive-image.png")'), 'normalized-image-reference-css');
$assert(str_contains($assetReferenceHtml, 'data-figma-vector="true"'), 'supported-vector-svg-html');
$assert(str_contains($assetReferenceHtml, '<path d="M 1 1 L 23 1 L 12 23 Z" fill="#0000ff" fill-rule="evenodd"/>'), 'supported-vector-path-derived-svg');
$assert(str_contains($assetReferenceHtml, 'data-figma-unsupported-vector="true"'), 'unsupported-vector-placeholder-html');
$assert(str_contains($assetReferenceHtml, 'Unsupported Figma VECTOR'), 'unsupported-vector-placeholder-text');
$assert(in_array('unsupported_vector_node_placeholder', $assetReferenceDiagnosticCodes, true), 'unsupported-vector-diagnostic');

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
$assert(str_contains($layoutFidelityCss, '.figma-node-5-2-fixed-card{width:100px;height:80px;opacity:0.6;transform:rotate(15deg)}'), 'layout-fixed-sizing-and-rotation');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-3-hug-label{width:fit-content;height:fit-content;font-size:12px}'), 'layout-hug-sizing');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-4-fill-panel{width:100%;height:100%;flex-grow:1;flex-shrink:1;align-self:stretch;order:2;z-index:2}'), 'layout-fill-sizing-and-order');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-5-absolute-badge{width:50px;height:20px;position:absolute;left:20px;right:430px;top:20px;bottom:260px;background:#000000;order:3;z-index:3}'), 'layout-absolute-constraints-and-z-index');
$assert(str_contains($layoutFidelityCss, '.figma-node-5-6-matrix-transform{width:30px;height:30px;transform:matrix(0,1,-1,0,40,60)}'), 'layout-relative-transform-matrix');
$assert(! str_contains($layoutFidelityCss, 'font-family:Inter') && ! str_contains($layoutFidelityCss, 'body{margin:0;background') && ! str_contains($layoutFidelityCss, 'body{margin:0;color'), 'layout-css-avoids-theme-defaults');

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
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate(json_encode(blocks_engine_figma_transformer_node_changes_fixture(), JSON_THROW_ON_ERROR)))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('{"NODE_CHANGES":'))
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
                        ),
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

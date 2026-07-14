<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\LayoutMismatchReportBuilder;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_layout_mismatch_contract(callable $assert): void
{
    $sourceNodes = array();
    $generatedBoxes = array();
    for ( $index = 1; $index <= 4; $index++ ) {
        $nodeId = 'limited:item:' . $index;
        $sourceNodes[] = array(
            'id'   => $nodeId,
            'name' => 'Limited item ' . $index,
            'type' => 'TEXT',
            'rect' => array('x' => 0, 'y' => $index * 40, 'width' => 120, 'height' => 20),
        );
        $generatedBoxes[] = array(
            'node_id' => $nodeId,
            'rect'    => array('x' => 80, 'y' => $index * 40, 'width' => 120 + $index, 'height' => 20),
        );
    }

    $report = ( new LayoutMismatchReportBuilder() )->build(
        array('visual_node_map' => $sourceNodes),
        array('boxes' => $generatedBoxes),
        array('threshold' => 24, 'limit' => 2)
    );

    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();
    $assert(4 === ($summary['diagnostic_count'] ?? null), 'layout-mismatch-total-count-uncapped');
    $assert(2 === ($summary['reported_diagnostic_count'] ?? null), 'layout-mismatch-reported-count-limited');
    $assert(true === ($summary['truncated'] ?? null), 'layout-mismatch-summary-truncated');
    $assert(4 === ($summary['code_counts']['misplaced_element'] ?? null), 'layout-mismatch-code-counts-uncapped');
    $assert(4 === ($summary['clusters']['repeated_position_delta'][0]['count'] ?? null), 'layout-mismatch-clusters-uncapped');
    $assert(2 === count($report['diagnostics'] ?? array()), 'layout-mismatch-diagnostics-limited');

    $fontReport = ( new LayoutMismatchReportBuilder() )->build(
        array(
            'font_families' => array('Example Sans'),
            'font_usage' => array(array('family' => 'Example Sans', 'weights' => array(400), 'text_node_count' => 1, 'visible_text_area_px' => 3200)),
            'visual_node_map' => array(array('id' => 'font:node', 'name' => 'Font node', 'type' => 'TEXT', 'rect' => array('x' => 0, 'y' => 0, 'width' => 160, 'height' => 20))),
            'node_style_diagnostics' => array(
                array(
                    'node' => array('id' => 'font:node', 'type' => 'TEXT'),
                    'expected' => array('font_family' => '"Example Sans"'),
                ),
            ),
        ),
        array('boxes' => array(array('node_id' => 'font:node', 'rect' => array('x' => 0, 'y' => 0, 'width' => 160, 'height' => 20), 'computed_style' => array('font-family' => 'Arial, sans-serif'), 'document_fonts_check' => false)))
    );
    $fontRendering = $fontReport['summary']['font_rendering'] ?? array();
    $assert('warn' === ($fontRendering['status'] ?? null), 'layout-mismatch-font-rendering-warn');
    $assert(1 === ($fontRendering['computed_font_family_mismatch_count'] ?? null), 'layout-mismatch-font-rendering-computed-family-mismatch');
    $assert(1 === ($fontRendering['document_fonts_check_failure_count'] ?? null), 'layout-mismatch-font-rendering-font-check-failure');
    $assert(3200 === ($fontRendering['source_font_usage'][0]['visible_text_area_px'] ?? null), 'layout-mismatch-font-rendering-source-usage-area');

    $fluidSource = array(
        'visual_node_map' => array(
            array('id' => 'fluid:band', 'name' => 'Fluid band', 'type' => 'FRAME', 'rect' => array('x' => 0, 'y' => 0, 'width' => 2095, 'height' => 276)),
            array('id' => 'fluid:primary', 'name' => 'Primary column', 'type' => 'FRAME', 'parent_id' => 'fluid:band', 'rect' => array('x' => 168, 'y' => 48, 'width' => 1205, 'height' => 180)),
            array('id' => 'fluid:secondary', 'name' => 'Secondary column', 'type' => 'FRAME', 'parent_id' => 'fluid:band', 'rect' => array('x' => 1421, 'y' => 48, 'width' => 506, 'height' => 180)),
        ),
    );
    $nativeBoxes = array(
        array('node_id' => 'fluid:band', 'rect' => array('x' => 0, 'y' => 0, 'width' => 2095, 'height' => 276)),
        array('node_id' => 'fluid:primary', 'rect' => array('x' => 168, 'y' => 48, 'width' => 1205, 'height' => 180)),
        array('node_id' => 'fluid:secondary', 'rect' => array('x' => 1421, 'y' => 48, 'width' => 506, 'height' => 180)),
    );
    $responsiveBoxes = array(
        array('node_id' => 'fluid:band', 'rect' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 504)),
        array('node_id' => 'fluid:primary', 'rect' => array('x' => 115, 'y' => 48, 'width' => 1210, 'height' => 180)),
        array('node_id' => 'fluid:secondary', 'rect' => array('x' => 115, 'y' => 276, 'width' => 1210, 'height' => 180)),
    );
    $fluidOptions = array('page_path' => 'index.html', 'source_frame_id' => 'fluid:2095', 'source_frame_width' => 2095);
    $nativeReport = ( new LayoutMismatchReportBuilder() )->build($fluidSource, array('entrypoints' => array(
        array('page_path' => 'index.html', 'viewport' => array('width' => 2095, 'height' => 900), 'source_frame' => array('id' => 'fluid:2095', 'width' => 2095), 'comparison_role' => 'source_layout', 'elements' => $nativeBoxes),
        array('page_path' => 'index.html', 'viewport' => array('width' => 1440, 'height' => 900), 'source_frame' => array('id' => 'fluid:1440', 'width' => 1440), 'comparison_role' => 'responsive_evidence', 'elements' => $responsiveBoxes),
    )), $fluidOptions);
    $assert('pass' === ($nativeReport['status'] ?? null), 'layout-mismatch-native-width-comparison-runs');
    $assert(2095.0 === ($nativeReport['comparison']['source_frame_width'] ?? null), 'layout-mismatch-persists-source-frame-width');
    $assert(2095 === ($nativeReport['comparison']['captured_viewport']['width'] ?? null), 'layout-mismatch-persists-captured-viewport');
    $assert(1 === ($nativeReport['comparison']['responsive_evidence_capture_count'] ?? null), 'layout-mismatch-preserves-responsive-capture-evidence');

    $incompatibleReport = ( new LayoutMismatchReportBuilder() )->build($fluidSource, array('entrypoints' => array(
        array('page_path' => 'index.html', 'viewport' => array('width' => 1440, 'height' => 900), 'source_frame' => array('id' => 'fluid:1440', 'width' => 1440), 'comparison_role' => 'responsive_evidence', 'elements' => $responsiveBoxes),
    )), $fluidOptions);
    $assert('not_comparable' === ($incompatibleReport['status'] ?? null), 'layout-mismatch-incompatible-width-is-not-comparable');
    $assert('not_comparable' === ($incompatibleReport['comparison']['position_status'] ?? null), 'layout-mismatch-incompatible-position-is-not-comparable');
    $assert('not_comparable' === ($incompatibleReport['comparison']['size_status'] ?? null), 'layout-mismatch-incompatible-size-is-not-comparable');
    $assert('incompatible_viewport_width' === ($incompatibleReport['comparison']['reason'] ?? null), 'layout-mismatch-incompatible-width-reason');
    $assert(0 === ($incompatibleReport['summary']['diagnostic_count'] ?? null), 'layout-mismatch-incompatible-width-does-not-create-failures');

    $variantGeometryReport = ( new LayoutMismatchReportBuilder() )->build($fluidSource, array('entrypoints' => array(
        array('page_path' => 'index.html', 'viewport' => array('width' => 1440, 'height' => 900), 'source_frame' => array('id' => 'fluid:1440', 'width' => 1440), 'comparison_role' => 'responsive_evidence', 'source_visual_node_map' => $responsiveBoxes, 'elements' => $responsiveBoxes),
    )), $fluidOptions);
    $assert('pass' === ($variantGeometryReport['status'] ?? null), 'layout-mismatch-responsive-geometry-can-be-compared');
    $assert('responsive_source_geometry_supplied' === ($variantGeometryReport['comparison']['reason'] ?? null), 'layout-mismatch-responsive-geometry-reason');

    $nativeMismatchBoxes = $nativeBoxes;
    $nativeMismatchBoxes[2]['rect']['x'] = 1300;
    $nativeMismatchReport = ( new LayoutMismatchReportBuilder() )->build($fluidSource, array('entrypoints' => array(
        array('page_path' => 'index.html', 'viewport' => array('width' => 2095, 'height' => 900), 'source_frame' => array('id' => 'fluid:2095', 'width' => 2095), 'comparison_role' => 'source_layout', 'elements' => $nativeMismatchBoxes),
    )), $fluidOptions);
    $assert('fail' === ($nativeMismatchReport['status'] ?? null), 'layout-mismatch-native-width-mismatch-still-fails');
    $assert(0 < ($nativeMismatchReport['summary']['diagnostic_count'] ?? 0), 'layout-mismatch-native-width-mismatch-remains-visible');

    $multiPageScenegraph = array(
        'name' => 'Matrix rerun fixture',
        'nodes' => array(
            array('id' => 'matrix:native', 'type' => 'FRAME', 'name' => 'Native page', 'width' => 1200, 'height' => 320),
            array('id' => 'matrix:responsive', 'type' => 'FRAME', 'name' => 'Responsive page', 'width' => 960, 'height' => 320),
        ),
    );
    $transformer = new \Automattic\BlocksEngine\FigmaTransformer\FigmaTransformer();
    $targetResult = $transformer->transformScenegraph($multiPageScenegraph, array('frame_ids' => array('matrix:native', 'matrix:responsive')))->toArray();
    $targetPages = $targetResult['source_reports']['figma']['html']['pages'] ?? array();
    $nativePage = is_array($targetPages[0] ?? null) ? $targetPages[0] : array();
    $responsivePage = is_array($targetPages[1] ?? null) ? $targetPages[1] : array();
    $nativeNode = is_array($nativePage['visual_node_map'][0] ?? null) ? $nativePage['visual_node_map'][0] : array();

    // This mirrors the DOM provider's entrypoint payload after matrix targeting.
    $rerunResult = $transformer->transformScenegraph($multiPageScenegraph, array(
        'frame_ids' => array('matrix:native', 'matrix:responsive'),
        'generated_dom_boxes' => array(
            'schema' => 'homeboy/static-artifact-dom-boxes/v1',
            'entrypoints' => array(
                array(
                    'page_path' => $nativePage['path'] ?? '',
                    'viewport' => array('width' => 1200, 'height' => 900),
                    'source_frame' => array('id' => 'matrix:native', 'width' => 1200),
                    'comparison_role' => 'source_layout',
                    'elements' => array(array('node_id' => $nativeNode['id'] ?? '', 'rect' => $nativeNode['rect'] ?? array())),
                ),
                array(
                    'page_path' => $responsivePage['path'] ?? '',
                    'viewport' => array('width' => 720, 'height' => 900),
                    'source_frame' => array('id' => 'matrix:responsive-capture', 'width' => 720),
                    'comparison_role' => 'responsive_evidence',
                    'elements' => array(array('node_id' => 'matrix:responsive', 'rect' => array('x' => 0, 'y' => 0, 'width' => 720, 'height' => 320))),
                ),
            ),
        ),
    ))->toArray();
    $rerunDiagnostics = $rerunResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $rerunPages = $rerunResult['source_reports']['figma']['html']['pages'] ?? array();
    $assert('pass' === ($rerunPages[0]['transform_diagnostics']['layout']['layout_mismatch_status'] ?? null), 'layout-mismatch-matrix-rerun-native-page-passes');
    $assert('not_comparable' === ($rerunPages[1]['transform_diagnostics']['layout']['layout_mismatch_status'] ?? null), 'layout-mismatch-matrix-rerun-wrong-width-is-not-comparable');
    $assert('not_comparable' === ($rerunDiagnostics['layout']['layout_mismatch_status'] ?? null), 'layout-mismatch-matrix-rerun-aggregate-preserves-not-comparable');
    $assert('needs_review' === ($rerunDiagnostics['artifact_quality']['status'] ?? null), 'layout-mismatch-matrix-rerun-aggregate-requires-review');
    $assert('warn' === ($rerunDiagnostics['artifact_quality']['quality_status'] ?? null), 'layout-mismatch-matrix-rerun-aggregate-quality-warn');
}

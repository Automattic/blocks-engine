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

    $componentLocalReport = ( new LayoutMismatchReportBuilder() )->build(
        array('visual_node_map' => array(
            array(
                'id'                  => 'component-local:child',
                'name'                => 'Component local child',
                'type'                => 'RECTANGLE',
                'coordinate_space'    => 'local',
                'geometry_confidence' => 'unresolved_component_local',
                'rect'                => array('x' => 12, 'y' => 8, 'width' => 40, 'height' => 24),
            ),
        )),
        array('boxes' => array(
            array('node_id' => 'component-local:child', 'rect' => array('x' => 500, 'y' => 400, 'width' => 70, 'height' => 24)),
        )),
        array('threshold' => 24)
    );
    $componentLocalDiagnostics = $componentLocalReport['diagnostics'] ?? array();
    $componentLocalWarnings = $componentLocalReport['warnings'] ?? array();
    $assert(array('element_size_mismatch') === array_column($componentLocalDiagnostics, 'code'), 'layout-mismatch-component-local-reports-size-mismatch');
    $assert(array('source_geometry_confidence') === array_column($componentLocalWarnings, 'code'), 'layout-mismatch-component-local-reports-confidence-warning');
    $assert('local' === ($componentLocalWarnings[0]['coordinate_space'] ?? null), 'layout-mismatch-component-local-reports-coordinate-space');
    $assert(1 === ($componentLocalReport['summary']['matched_node_count'] ?? null), 'layout-mismatch-component-local-keeps-size-parity-match');
    $assert(1 === ($componentLocalReport['summary']['diagnostic_count'] ?? null), 'layout-mismatch-component-local-mismatch-count-excludes-warning');
    $assert(1 === ($componentLocalReport['summary']['warning_count'] ?? null), 'layout-mismatch-component-local-warning-count');

    $componentLocalCleanReport = ( new LayoutMismatchReportBuilder() )->build(
        array('visual_node_map' => array(
            array(
                'id' => 'component-local:clean',
                'coordinate_space' => 'local',
                'geometry_confidence' => 'unresolved_component_local',
                'rect' => array('x' => 12, 'y' => 8, 'width' => 40, 'height' => 24),
            ),
        )),
        array('boxes' => array(
            array('node_id' => 'component-local:clean', 'rect' => array('x' => 500, 'y' => 400, 'width' => 40, 'height' => 24)),
        ))
    );
    $assert('pass' === ($componentLocalCleanReport['status'] ?? null), 'layout-mismatch-component-local-warning-does-not-fail-clean-comparison');
    $assert(0 === ($componentLocalCleanReport['summary']['diagnostic_count'] ?? null), 'layout-mismatch-component-local-clean-mismatch-count');
    $assert(1 === ($componentLocalCleanReport['summary']['warning_count'] ?? null), 'layout-mismatch-component-local-clean-warning-count');

    $componentTransformReport = ( new LayoutMismatchReportBuilder() )->build(
        array('visual_node_map' => array(
            array('id' => 'component-transform:child', 'rect' => array('x' => 12, 'y' => 8, 'width' => 40, 'height' => 24)),
        )),
        array('boxes' => array(
            array('node_id' => 'component-transform:child', 'rect' => array('x' => 500, 'y' => 400, 'width' => 40, 'height' => 24)),
        )),
        array('threshold' => 24)
    );
    $assert('fail' === ($componentTransformReport['status'] ?? null), 'layout-mismatch-component-transform-remains-comparable');
    $assert(array('misplaced_element') === array_column($componentTransformReport['diagnostics'] ?? array(), 'code'), 'layout-mismatch-component-transform-reports-position');
    $assert(0 === ($componentTransformReport['summary']['warning_count'] ?? null), 'layout-mismatch-component-transform-no-confidence-warning');
}

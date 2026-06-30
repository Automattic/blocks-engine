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
}

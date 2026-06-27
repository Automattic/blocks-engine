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
}

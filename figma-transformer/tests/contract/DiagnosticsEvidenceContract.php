<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_diagnostics_evidence_contract(callable $assert): void
{
    $normalResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Normal Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:normal-page',
                'type'     => 'FRAME',
                'name'     => 'Normal Page',
                'width'    => 320,
                'height'   => 160,
                'children' => array(
                    array('id' => 'diag:normal-title', 'type' => 'TEXT', 'name' => 'Normal Title', 'text' => 'Visible title', 'width' => 120, 'height' => 24),
                ),
            ),
        ),
    ));
    $normalDiagnostics = $normalResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $normalSignalCodes = blocks_engine_figma_transformer_contract_artifact_quality_signal_codes($normalResult);
    $assert(1 === ($normalDiagnostics['text']['decoded_text_node_count'] ?? null), 'diagnostics-evidence-normal-decoded-text-count');
    $assert(1 === ($normalDiagnostics['text']['emitted_text_node_count'] ?? null), 'diagnostics-evidence-normal-emitted-text-count');
    $assert(0 === ($normalDiagnostics['text']['missing_emitted_text_node_count'] ?? null), 'diagnostics-evidence-normal-missing-text-zero');
    $assert(0 === ($normalDiagnostics['layout']['clipped_visual_node_count'] ?? null), 'diagnostics-evidence-normal-clipped-zero');
    $assert(! in_array('decoded_text_not_emitted', $normalSignalCodes, true), 'diagnostics-evidence-normal-no-missing-text-signal');
    $assert(! in_array('clipped_visual_area', $normalSignalCodes, true), 'diagnostics-evidence-normal-no-clipped-area-signal');

    $clippedResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Clipped Fixture',
        'nodes' => array(
            array(
                'id'           => 'diag:clip-page',
                'type'         => 'FRAME',
                'name'         => 'Clipped Page',
                'width'        => 100,
                'height'       => 100,
                'clipsContent' => true,
                'children'     => array(
                    array('id' => 'diag:clip-vector', 'type' => 'VECTOR', 'name' => 'Half clipped vector', 'x' => -50, 'y' => 0, 'width' => 100, 'height' => 100),
                    array('id' => 'diag:clip-copy', 'type' => 'TEXT', 'name' => 'Copy', 'text' => 'Copy remains emitted', 'x' => 0, 'y' => 0, 'width' => 80, 'height' => 20),
                ),
            ),
        ),
    ));
    $clippedDiagnostics = $clippedResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $clippedSignalCodes = blocks_engine_figma_transformer_contract_artifact_quality_signal_codes($clippedResult);
    $assert(1 === ($clippedDiagnostics['layout']['clipped_visual_node_count'] ?? null), 'diagnostics-evidence-clipped-count');
    $assert(0.5 === ($clippedDiagnostics['layout']['clipped_visual_area_ratio'] ?? null), 'diagnostics-evidence-clipped-area-ratio');
    $assert('diag:clip-vector' === ($clippedDiagnostics['layout']['clipped_visual_nodes'][0]['node_id'] ?? null), 'diagnostics-evidence-clipped-sample-node');
    $assert(in_array('clipped_visual_area', $clippedSignalCodes, true), 'diagnostics-evidence-clipped-area-signal');

    $emptyTextResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Empty Text Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:empty-page',
                'type'     => 'FRAME',
                'name'     => 'Empty Text Page',
                'width'    => 240,
                'height'   => 120,
                'children' => array(
                    array('id' => 'diag:empty-text', 'type' => 'TEXT', 'name' => 'Decoded Empty Text', 'text' => '', 'width' => 80, 'height' => 20),
                ),
            ),
        ),
    ));
    $emptyTextDiagnostics = $emptyTextResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $emptyTextSignalCodes = blocks_engine_figma_transformer_contract_artifact_quality_signal_codes($emptyTextResult);
    $assert(1 === ($emptyTextDiagnostics['text']['empty_decoded_text_node_count'] ?? null), 'diagnostics-evidence-empty-text-count');
    $assert('diag:empty-text' === ($emptyTextDiagnostics['text']['empty_decoded_text_nodes'][0]['node_id'] ?? null), 'diagnostics-evidence-empty-text-sample-node');
    $assert('Empty Text Page' === ($emptyTextDiagnostics['text']['empty_decoded_text_nodes'][0]['page_name'] ?? null), 'diagnostics-evidence-empty-text-page-context');
    $assert(in_array('decoded_text_empty', $emptyTextSignalCodes, true), 'diagnostics-evidence-empty-text-signal');
}

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

    $largeOffsetResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Large Offset Classification Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:large-offset-page',
                'type'     => 'FRAME',
                'name'     => 'Large Offset Page',
                'width'    => 320,
                'height'   => 160,
                'children' => array(
                    array('id' => 'diag:large-offset-empty', 'type' => 'INSTANCE', 'name' => 'Empty Clone Shell', 'x' => 2000, 'y' => 0, 'width' => 80, 'height' => 40),
                ),
            ),
        ),
    ));
    $largeOffsetDiagnostics = $largeOffsetResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $largeOffsetNodes = $largeOffsetDiagnostics['layout']['large_css_offset_nodes'] ?? array();
    $assert('empty_visible_container' === ($largeOffsetNodes[0]['classification'] ?? null), 'diagnostics-evidence-large-css-offset-classification');

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

    $maskMetadataResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Mask Metadata Fixture',
        'nodes' => array(
            array(
                'id'                => 'diag:mask-frame',
                'type'              => 'FRAME',
                'name'              => 'Mask Clip Frame',
                'width'             => 120,
                'height'            => 80,
                'frameMaskDisabled' => false,
                'children'          => array(
                    array(
                        'id'       => 'diag:mask-source',
                        'type'     => 'VECTOR',
                        'name'     => 'Alpha Mask Source',
                        'width'    => 80,
                        'height'   => 80,
                        'isMask'   => true,
                        'maskType' => 'ALPHA',
                    ),
                    array(
                        'id'     => 'diag:clip-source',
                        'type'   => 'FRAME',
                        'name'   => 'Explicit Clip Source',
                        'width'  => 20,
                        'height' => 20,
                        'isClip' => true,
                    ),
                ),
            ),
        ),
    ));
    $maskMetadataDiagnostics = $maskMetadataResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $maskEffectClipping = $maskMetadataDiagnostics['mask_effect_clipping'] ?? array();
    $assert(1 === ($maskEffectClipping['mask_node_count'] ?? null), 'diagnostics-evidence-mask-node-count');
    $assert(3 === ($maskEffectClipping['mask_metadata_node_count'] ?? null), 'diagnostics-evidence-mask-metadata-node-count');
    $assert(2 === ($maskEffectClipping['clips_content_node_count'] ?? null), 'diagnostics-evidence-mask-clips-content-count');
    $assert(1 === ($maskEffectClipping['by_mask_type']['ALPHA'] ?? null), 'diagnostics-evidence-mask-type-count');
    $assert(false === ($maskEffectClipping['sample_nodes'][0]['frame_mask_disabled'] ?? null), 'diagnostics-evidence-frame-mask-disabled-sample');
    $assert(true === ($maskEffectClipping['sample_nodes'][1]['is_mask'] ?? null), 'diagnostics-evidence-is-mask-sample');
    $assert('ALPHA' === ($maskEffectClipping['sample_nodes'][1]['type'] ?? null), 'diagnostics-evidence-mask-type-sample');
    $assert(true === ($maskEffectClipping['sample_nodes'][2]['is_clip'] ?? null), 'diagnostics-evidence-is-clip-sample');
    $assert(1 === ($maskMetadataDiagnostics['artifact_quality']['summary']['mask_nodes'] ?? null), 'diagnostics-evidence-artifact-summary-mask-nodes');
    $assert(3 === ($maskMetadataDiagnostics['artifact_quality']['summary']['mask_metadata_nodes'] ?? null), 'diagnostics-evidence-artifact-summary-mask-metadata-nodes');

    $multiPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Diagnostics Aggregation Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:aggregation-canvas',
                'type'     => 'CANVAS',
                'name'     => 'Aggregation Pages',
                'children' => array(
                    array(
                        'id'       => 'diag:aggregation-home',
                        'type'     => 'FRAME',
                        'name'     => 'Aggregation Home',
                        'width'    => 320,
                        'height'   => 180,
                        'children' => array(
                            array('id' => 'diag:aggregation-home-image', 'type' => 'RECTANGLE', 'name' => 'Missing Home Asset', 'width' => 80, 'height' => 60, 'asset_id' => 'missing-home'),
                            array('id' => 'diag:aggregation-home-vector', 'type' => 'VECTOR', 'name' => 'Unsupported Home Vector'),
                        ),
                    ),
                    array(
                        'id'       => 'diag:aggregation-about',
                        'type'     => 'FRAME',
                        'name'     => 'Aggregation About',
                        'width'    => 320,
                        'height'   => 180,
                        'children' => array(
                            array('id' => 'diag:aggregation-about-image', 'type' => 'RECTANGLE', 'name' => 'Missing About Asset', 'width' => 80, 'height' => 60, 'asset_id' => 'missing-about'),
                            array('id' => 'diag:aggregation-about-vector', 'type' => 'VECTOR', 'name' => 'Unsupported About Vector'),
                        ),
                    ),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('diag:aggregation-home', 'diag:aggregation-about'), 'entry_frame_id' => 'diag:aggregation-home'));
    $multiPageDiagnostics = $multiPageResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $missingAssets = $multiPageDiagnostics['images']['missing_assets'] ?? array();
    $placeholderNodes = $multiPageDiagnostics['vectors']['placeholder_nodes'] ?? array();
    $assert('multi_page' === ($multiPageDiagnostics['scope'] ?? null), 'diagnostics-evidence-multi-page-scope');
    $assert(2 === ($multiPageDiagnostics['images']['node_refs'] ?? null), 'diagnostics-evidence-multi-page-image-node-count');
    $assert(2 === count(is_array($missingAssets) ? $missingAssets : array()), 'diagnostics-evidence-multi-page-missing-asset-sample-count');
    $assert('index.html' === ($missingAssets[0]['page_path'] ?? null), 'diagnostics-evidence-multi-page-missing-asset-home-context');
    $assert('aggregation-about.html' === ($missingAssets[1]['page_path'] ?? null), 'diagnostics-evidence-multi-page-missing-asset-about-context');
    $assert(2 === ($multiPageDiagnostics['vectors']['placeholders'] ?? null), 'diagnostics-evidence-multi-page-vector-placeholder-count');
    $assert(2 === count(is_array($placeholderNodes) ? $placeholderNodes : array()), 'diagnostics-evidence-multi-page-placeholder-sample-count');
    $assert('diag:aggregation-home-vector' === ($placeholderNodes[0]['node_id'] ?? null), 'diagnostics-evidence-multi-page-placeholder-home-node');
    $assert('aggregation-about.html' === ($placeholderNodes[1]['page_path'] ?? null), 'diagnostics-evidence-multi-page-placeholder-about-context');
    $assert(2 === ($multiPageDiagnostics['diagnostic_codes']['unsupported_vector_node_placeholder'] ?? null), 'diagnostics-evidence-multi-page-diagnostic-code-count');
}

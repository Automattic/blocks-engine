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
    $normalDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($normalResult);
    blocks_engine_figma_transformer_contract_assert_diagnostic_envelope($assert, $normalDiagnostics, 'blocks-engine/figma-transformer/transform-diagnostics/v1', 'diagnostics-evidence-normal-envelope');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('text', 'decoded_text_node_count'), 1, 'diagnostics-evidence-normal-decoded-text-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('text', 'emitted_text_node_count'), 1, 'diagnostics-evidence-normal-emitted-text-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('text', 'missing_emitted_text_node_count'), 0, 'diagnostics-evidence-normal-missing-text-zero');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('layout', 'clipped_visual_node_count'), 0, 'diagnostics-evidence-normal-clipped-zero');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('layout', 'positional_parity', 'schema'), 'blocks-engine/figma-transformer/positional-parity/v1', 'diagnostics-evidence-normal-positional-parity-schema');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('layout', 'positional_parity', 'mirrored_transform_count'), 0, 'diagnostics-evidence-normal-positional-mirror-zero');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('layout', 'positional_parity', 'fixed_over_root_width_underlay_count'), 0, 'diagnostics-evidence-normal-positional-underlay-zero');
    $assert(is_array($normalDiagnostics['layout']['positional_parity']['decision_trace_samples'] ?? null), 'diagnostics-evidence-normal-positional-decision-trace-samples');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('visual_node_map_summary', 'schema'), 'blocks-engine/figma-transformer/visual-node-map-summary/v1', 'diagnostics-evidence-normal-visual-map-summary-schema');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('visual_node_map_summary', 'visual_node_count'), 2, 'diagnostics-evidence-normal-visual-map-summary-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('visual_node_map_summary', 'nodes_with_emitted_metadata'), 2, 'diagnostics-evidence-normal-visual-map-summary-emitted-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('visual_node_map_summary', 'page_path_counts', 'index.html'), 2, 'diagnostics-evidence-normal-visual-map-summary-page-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('visual_node_map_summary', 'emitted_class_samples', 0, 'node_id'), 'diag:normal-page', 'diagnostics-evidence-normal-visual-map-summary-sample-node');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('artifact_quality', 'summary', 'source_loss_coverage', 'schema'), 'blocks-engine/figma-transformer/source-loss-coverage/v1', 'diagnostics-evidence-normal-source-loss-schema');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('decision_traces', 'schema'), 'blocks-engine/figma-transformer/decision-traces/v1', 'diagnostics-evidence-normal-decision-traces-schema');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $normalDiagnostics, array('artifact_quality', 'summary', 'source_loss_coverage', 'coverage_ratio'), 1.0, 'diagnostics-evidence-normal-source-loss-clean-ratio');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $normalResult, 'decoded_text_not_emitted', 'diagnostics-evidence-normal-no-missing-text-signal');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $normalResult, 'clipped_visual_area', 'diagnostics-evidence-normal-no-clipped-area-signal');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $normalResult, 'source_loss_coverage_gap', 'diagnostics-evidence-normal-no-source-loss-signal');

    $positioningTraceResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Positioning Trace Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:positioning-page',
                'type'     => 'FRAME',
                'name'     => 'Positioning Page',
                'width'    => 320,
                'height'   => 200,
                'children' => array(
                    array('id' => 'diag:absolute-box', 'type' => 'RECTANGLE', 'name' => 'Absolute Box', 'x' => 10, 'y' => 20, 'width' => 40, 'height' => 30, 'layout' => array('positioning' => 'absolute')),
                ),
            ),
        ),
    ));
    $positioningTraceDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($positioningTraceResult);
    $positioningTraceSample = null;
    foreach ( $positioningTraceDiagnostics['decision_traces']['samples'] ?? array() as $trace ) {
        if ( is_array($trace) && 'positioning_context' === ($trace['domain'] ?? null) ) {
            $positioningTraceSample = $trace;
            break;
        }
    }
    $assert(1 === ($positioningTraceDiagnostics['decision_traces']['domain_counts']['positioning_context'] ?? null), 'diagnostics-evidence-positioning-context-trace-count');
    $assert('freeform_parent_absolute_child' === ($positioningTraceDiagnostics['decision_traces']['samples_by_reason']['freeform_parent_absolute_child']['reason_code'] ?? null), 'diagnostics-evidence-positioning-context-sample-by-reason');
    $assert('freeform_parent_absolute_child' === ($positioningTraceSample['reason_code'] ?? null), 'diagnostics-evidence-positioning-context-reason');
    $assert(in_array('position:absolute', $positioningTraceSample['evidence']['positioning_declarations'] ?? array(), true), 'diagnostics-evidence-positioning-context-declarations');

    $localCardResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Local Card Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:card-page',
                'type'     => 'FRAME',
                'name'     => 'Card Page',
                'width'    => 800,
                'height'   => 520,
                'children' => array(
                    array('id' => 'diag:card-date', 'type' => 'TEXT', 'name' => 'Card Date', 'text' => 'July 27, 2021', 'x' => 360, 'y' => 148, 'width' => 120, 'height' => 24),
                    array('id' => 'diag:card-title', 'type' => 'TEXT', 'name' => 'Card Title', 'text' => 'Fever in Newborns', 'x' => 360, 'y' => 184, 'width' => 260, 'height' => 56),
                    array('id' => 'diag:card-image', 'type' => 'RECTANGLE', 'name' => 'Card Image', 'x' => 100, 'y' => 120, 'width' => 220, 'height' => 160, 'fillPaints' => array(array('type' => 'IMAGE', 'imageHash' => 'fixture-photo'))),
                    array('id' => 'diag:card-shell', 'type' => 'RECTANGLE', 'name' => 'Card Shell', 'x' => 80, 'y' => 100, 'width' => 620, 'height' => 220, 'strokeWeight' => 1, 'strokeAlign' => 'INSIDE', 'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0.5, 'b' => 0.6, 'a' => 1)))),
                    array('id' => 'diag:card-arrow', 'type' => 'VECTOR', 'name' => 'Card Arrow', 'x' => 620, 'y' => 244, 'width' => 32, 'height' => 24, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0.5, 'b' => 0.6, 'a' => 1)))),
                ),
            ),
        ),
        'assets' => array(
            'fixture-photo' => array('name' => 'Fixture Photo', 'mime_type' => 'image/jpeg', 'content' => 'fixture image'),
        ),
    ));
    $localCardHtml = blocks_engine_figma_transformer_contract_file_content($localCardResult, 'index.html');
    $localCardCss = blocks_engine_figma_transformer_contract_file_content($localCardResult, 'style.css');
    $localCardDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($localCardResult);
    $localClusterRule = blocks_engine_figma_transformer_contract_css_rule($localCardCss, '.figma-node-diag-card-page-local-cluster-diag-card-shell-local-border-shell-cluster');
    $localImageRule = blocks_engine_figma_transformer_contract_css_rule($localCardCss, '.figma-node-diag-card-image-card-image');
    $localTitleRule = blocks_engine_figma_transformer_contract_css_rule($localCardCss, '.figma-node-diag-card-title-card-title');
    $assert(strpos($localCardHtml, 'data-figma-node-id="diag:card-page/local-cluster-diag:card-shell"') < strpos($localCardHtml, 'data-figma-node-id="diag:card-date"'), 'diagnostics-evidence-local-card-cluster-wraps-first-member');
    $assert(str_contains($localClusterRule, 'left:80px') && str_contains($localClusterRule, 'top:100px') && str_contains($localClusterRule, 'isolation:isolate'), 'diagnostics-evidence-local-card-cluster-shell-position');
    $assert(str_contains($localImageRule, 'left:20px') && str_contains($localImageRule, 'top:20px'), 'diagnostics-evidence-local-card-image-local-offset');
    $assert(str_contains($localTitleRule, 'left:280px') && str_contains($localTitleRule, 'top:84px'), 'diagnostics-evidence-local-card-title-local-offset');
    $assert(1 === ($localCardDiagnostics['decision_traces']['domain_counts']['local_coordinate_grouping'] ?? null), 'diagnostics-evidence-local-card-grouping-trace-count');

    $compactTextCardResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Compact Text Card Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:compact-card-page',
                'type'     => 'FRAME',
                'name'     => 'Compact Card Page',
                'width'    => 800,
                'height'   => 420,
                'children' => array(
                    array('id' => 'diag:compact-card-title', 'type' => 'TEXT', 'name' => 'Compact Card Title', 'text' => 'Probiotics: Your Gut Friend', 'x' => 120, 'y' => 132, 'width' => 300, 'height' => 64),
                    array('id' => 'diag:compact-card-shell', 'type' => 'RECTANGLE', 'name' => 'Compact Card Shell', 'x' => 100, 'y' => 100, 'width' => 350, 'height' => 165, 'strokeWeight' => 1, 'strokeAlign' => 'INSIDE', 'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.8, 'g' => 0.88, 'b' => 0.53, 'a' => 1)))),
                ),
            ),
        ),
    ));
    $compactTextCardHtml = blocks_engine_figma_transformer_contract_file_content($compactTextCardResult, 'index.html');
    $compactTextCardCss = blocks_engine_figma_transformer_contract_file_content($compactTextCardResult, 'style.css');
    $compactTextCardDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($compactTextCardResult);
    $compactClusterRule = blocks_engine_figma_transformer_contract_css_rule($compactTextCardCss, '.figma-node-diag-compact-card-page-local-cluster-diag-compact-card-shell-local-border-shell-cluster');
    $compactTitleRule = blocks_engine_figma_transformer_contract_css_rule($compactTextCardCss, '.figma-node-diag-compact-card-title-compact-card-title');
    $assert(strpos($compactTextCardHtml, 'data-figma-node-id="diag:compact-card-page/local-cluster-diag:compact-card-shell"') < strpos($compactTextCardHtml, 'data-figma-node-id="diag:compact-card-title"'), 'diagnostics-evidence-compact-text-card-cluster-wraps-title');
    $assert(str_contains($compactClusterRule, 'left:100px') && str_contains($compactClusterRule, 'top:100px'), 'diagnostics-evidence-compact-text-card-shell-position');
    $assert(str_contains($compactTitleRule, 'left:20px') && str_contains($compactTitleRule, 'top:32px'), 'diagnostics-evidence-compact-text-card-title-local-offset');
    $assert(1 === ($compactTextCardDiagnostics['decision_traces']['domain_counts']['local_coordinate_grouping'] ?? null), 'diagnostics-evidence-compact-text-card-grouping-trace-count');

    $styleReferenceFallbackResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Style Reference Fallback Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:style-ref-page',
                'type'     => 'FRAME',
                'name'     => 'Style Reference Page',
                'width'    => 320,
                'height'   => 160,
                'children' => array(
                    array(
                        'id'             => 'diag:style-ref-paint-local',
                        'type'           => 'RECTANGLE',
                        'name'           => 'Local Paint Preserved',
                        'width'          => 120,
                        'height'         => 40,
                        'styleIdForFill' => 'missing-fill-style',
                        'fills'          => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3, 'a' => 1))),
                    ),
                    array(
                        'id'             => 'diag:style-ref-paint-missing',
                        'type'           => 'RECTANGLE',
                        'name'           => 'Missing Paint Only',
                        'x'              => 0,
                        'y'              => 48,
                        'width'          => 120,
                        'height'         => 40,
                        'styleIdForFill' => 'missing-fill-only',
                    ),
                    array(
                        'id'             => 'diag:style-ref-text-local',
                        'type'           => 'TEXT',
                        'name'           => 'Local Text Style Preserved',
                        'text'           => 'Styled locally',
                        'x'              => 0,
                        'y'              => 96,
                        'width'          => 140,
                        'height'         => 24,
                        'styleIdForText' => 'missing-text-style',
                        'style'          => array('fontFamily' => 'Inter', 'fontSize' => 16),
                    ),
                    array(
                        'id'             => 'diag:style-ref-text-missing',
                        'type'           => 'TEXT',
                        'name'           => 'Missing Text Style Only',
                        'text'           => 'Unstyled reference',
                        'x'              => 0,
                        'y'              => 128,
                        'width'          => 140,
                        'height'         => 24,
                        'styleIdForText' => 'missing-text-only',
                    ),
                ),
            ),
        ),
    ));
    $styleReferenceDiagnostics = $styleReferenceFallbackResult['diagnostics'] ?? array();
    $styleReferenceSeverity = static function (array $diagnostics, string $code, string $nodeId): ?string {
        foreach ( $diagnostics as $diagnostic ) {
            if ( $code === ($diagnostic['code'] ?? null) && $nodeId === ($diagnostic['context']['node_id'] ?? null) ) {
                return (string) ($diagnostic['severity'] ?? '');
            }
        }
        return null;
    };
    $assert('info' === $styleReferenceSeverity($styleReferenceDiagnostics, 'figma_missing_paint_style_reference', 'diag:style-ref-paint-local'), 'diagnostics-evidence-local-paint-style-reference-info');
    $assert('warning' === $styleReferenceSeverity($styleReferenceDiagnostics, 'figma_missing_paint_style_reference', 'diag:style-ref-paint-missing'), 'diagnostics-evidence-missing-paint-style-reference-warning');
    $assert('info' === $styleReferenceSeverity($styleReferenceDiagnostics, 'figma_missing_text_style_reference', 'diag:style-ref-text-local'), 'diagnostics-evidence-local-text-style-reference-info');
    $assert('warning' === $styleReferenceSeverity($styleReferenceDiagnostics, 'figma_missing_text_style_reference', 'diag:style-ref-text-missing'), 'diagnostics-evidence-missing-text-style-reference-warning');

    $invalidCssQuality = (new \Automattic\BlocksEngine\FigmaTransformer\Html\TransformDiagnosticsBuilder())->artifactQualityDiagnostics(
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(),
        array(
            'invalid_numeric_token_count' => 2,
            'invalid_numeric_tokens' => array(
                array('token' => 'NaN', 'offset' => 12),
                array('token' => 'Infinity', 'offset' => 40),
            ),
        )
    );
    $invalidCssCodes = array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), is_array($invalidCssQuality['signals'] ?? null) ? $invalidCssQuality['signals'] : array());
    $assert(in_array('invalid_css_numeric_token', $invalidCssCodes, true), 'diagnostics-evidence-invalid-css-quality-signal');
    $assert('needs_review' === ($invalidCssQuality['status'] ?? null), 'diagnostics-evidence-invalid-css-not-clean');
    $assert('fail' === ($invalidCssQuality['quality_status'] ?? null), 'diagnostics-evidence-invalid-css-quality-fail');
    $assert(2 === ($invalidCssQuality['summary']['invalid_css_numeric_tokens'] ?? null), 'diagnostics-evidence-invalid-css-summary-count');

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
    $clippedDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($clippedResult);
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $clippedDiagnostics, array('layout', 'clipped_visual_node_count'), 1, 'diagnostics-evidence-clipped-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $clippedDiagnostics, array('layout', 'clipped_visual_area_ratio'), 0.5, 'diagnostics-evidence-clipped-area-ratio');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $clippedDiagnostics, array('layout', 'clipped_visual_nodes', 0, 'node_id'), 'diag:clip-vector', 'diagnostics-evidence-clipped-sample-node');
    blocks_engine_figma_transformer_contract_assert_quality_signal($assert, $clippedResult, 'clipped_visual_area', 'diagnostics-evidence-clipped-area-signal');

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
    $largeOffsetDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($largeOffsetResult);
    $largeOffsetNodes = $largeOffsetDiagnostics['layout']['large_css_offset_nodes'] ?? array();
    $assert('empty_visible_container' === ($largeOffsetNodes[0]['classification'] ?? null), 'diagnostics-evidence-large-css-offset-classification');
    $assert('empty_visible_container' === ($largeOffsetNodes[0]['reason_code'] ?? null), 'diagnostics-evidence-large-css-offset-reason-code');

    $decorativeHairlineResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Decorative Hairline Offset Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:hairline-page',
                'type'     => 'FRAME',
                'name'     => 'Hairline Page',
                'width'    => 320,
                'height'   => 1300,
                'children' => array(
                    array(
                        'id'         => 'diag:hairline',
                        'type'       => 'RECTANGLE',
                        'name'       => 'Decorative Divider',
                        'x'          => 0,
                        'y'          => 1200,
                        'width'      => 320,
                        'height'     => 1,
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.9, 'g' => 0.9, 'b' => 0.9, 'a' => 1))),
                    ),
                ),
            ),
        ),
    ));
    $decorativeHairlineDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($decorativeHairlineResult);
    $assert(0 === ($decorativeHairlineDiagnostics['layout']['large_css_offset_count'] ?? null), 'diagnostics-evidence-decorative-hairline-no-large-css-offset');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $decorativeHairlineResult, 'large_css_offsets', 'diagnostics-evidence-decorative-hairline-no-quality-signal');

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
    $emptyTextDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($emptyTextResult);
    $emptyTextSignalCodes = blocks_engine_figma_transformer_contract_artifact_quality_signal_codes($emptyTextResult);
    $assert(1 === ($emptyTextDiagnostics['text']['empty_decoded_text_node_count'] ?? null), 'diagnostics-evidence-empty-text-count');
    $assert('diag:empty-text' === ($emptyTextDiagnostics['text']['empty_decoded_text_nodes'][0]['node_id'] ?? null), 'diagnostics-evidence-empty-text-sample-node');
    $assert('Empty Text Page' === ($emptyTextDiagnostics['text']['empty_decoded_text_nodes'][0]['page_name'] ?? null), 'diagnostics-evidence-empty-text-page-context');
    $assert(in_array('decoded_text_empty', $emptyTextSignalCodes, true), 'diagnostics-evidence-empty-text-signal');

    $omittedTextResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Omitted Text Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:omitted-text-page',
                'type'     => 'FRAME',
                'name'     => 'Omitted Text Page',
                'width'    => 240,
                'height'   => 120,
                'children' => array(
                    array('id' => 'diag:hidden-text', 'type' => 'TEXT', 'name' => 'Hidden Text', 'text' => 'Hidden copy', 'visible' => false, 'width' => 80, 'height' => 20),
                ),
            ),
        ),
    ));
    $omittedTextDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($omittedTextResult);
    $omittedTextReasons = $omittedTextDiagnostics['text']['missing_emitted_text_reason_categories'] ?? array();
    $intentionalTextReasons = $omittedTextDiagnostics['text']['intentional_suppression_reason_counts'] ?? array();
    $assert(0 === ($omittedTextDiagnostics['text']['missing_emitted_text_node_count'] ?? null), 'diagnostics-evidence-omitted-text-count');
    $assert(array() === $omittedTextReasons, 'diagnostics-evidence-omitted-text-no-missing-reasons');
    $assert(1 === ($omittedTextDiagnostics['text']['intentionally_suppressed_text_node_count'] ?? null), 'diagnostics-evidence-omitted-text-intentional-count');
    $assert(1 === ($intentionalTextReasons['hidden'] ?? null), 'diagnostics-evidence-omitted-text-hidden-reason');
    $assert('hidden' === ($omittedTextDiagnostics['text']['intentionally_suppressed_text_nodes'][0]['reason'] ?? null), 'diagnostics-evidence-omitted-text-sample-reason');
    $assert(1 === ($omittedTextDiagnostics['decision_traces']['reason_counts']['hidden_descendant_suppressed'] ?? null), 'diagnostics-evidence-omitted-text-decision-trace-reason');
    $assert(0 === ($omittedTextDiagnostics['artifact_quality']['summary']['source_loss_coverage']['domains']['text']['not_emitted_source_nodes'] ?? null), 'diagnostics-evidence-source-loss-text-domain-count');
    $assert(1 === ($omittedTextDiagnostics['artifact_quality']['summary']['source_loss_coverage']['domains']['text']['intentionally_suppressed_source_nodes'] ?? null), 'diagnostics-evidence-source-loss-text-intentional-domain-count');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $omittedTextResult, 'source_loss_coverage_gap', 'diagnostics-evidence-hidden-text-no-source-loss-signal');

    $suppressedEffectResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Suppressed Effect Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:suppressed-effect-page',
                'type'     => 'FRAME',
                'name'     => 'Suppressed Effect Page',
                'width'    => 240,
                'height'   => 120,
                'children' => array(
                    array(
                        'id'            => 'diag:hidden-effect',
                        'type'          => 'RECTANGLE',
                        'name'          => 'Hidden Effect Chrome',
                        'visible'       => false,
                        'width'         => 80,
                        'height'        => 20,
                        'figma_effects' => array(array('type' => 'DROP_SHADOW', 'visible' => true, 'radius' => 8)),
                    ),
                    array(
                        'id'            => 'diag:zero-effect',
                        'type'          => 'RECTANGLE',
                        'name'          => 'Zero Area Effect Chrome',
                        'width'         => 0,
                        'height'        => 20,
                        'figma_effects' => array(array('type' => 'DROP_SHADOW', 'visible' => true, 'radius' => 8)),
                    ),
                ),
            ),
        ),
    ));
    $suppressedEffectDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($suppressedEffectResult);
    $effectCoverage = $suppressedEffectDiagnostics['effects'] ?? array();
    $assert(2 === ($effectCoverage['source_effect_node_count'] ?? null), 'diagnostics-evidence-effect-source-count');
    $assert(0 === ($effectCoverage['missing_emitted_effect_node_count'] ?? null), 'diagnostics-evidence-effect-no-missing-count');
    $assert(2 === ($effectCoverage['intentionally_suppressed_effect_node_count'] ?? null), 'diagnostics-evidence-effect-intentional-count');
    $assert(array('hidden' => 1, 'zero_area' => 1) === ($effectCoverage['intentional_suppression_reason_counts'] ?? null), 'diagnostics-evidence-effect-intentional-reasons');
    $assert(0 === ($suppressedEffectDiagnostics['artifact_quality']['summary']['source_loss_coverage']['domains']['effects']['not_emitted_source_nodes'] ?? null), 'diagnostics-evidence-effect-source-loss-no-gap');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $suppressedEffectResult, 'effect_node_not_emitted', 'diagnostics-evidence-suppressed-effect-no-quality-signal');

    $assetOmissionResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Diagnostics Asset Omission Fixture',
        'nodes' => array(
            array(
                'id'       => 'diag:asset-page',
                'type'     => 'FRAME',
                'name'     => 'Asset Page',
                'width'    => 240,
                'height'   => 120,
                'children' => array(
                    array('id' => 'diag:missing-asset', 'type' => 'RECTANGLE', 'name' => 'Missing Asset', 'asset_id' => 'archive-missing', 'width' => 80, 'height' => 60),
                ),
            ),
        ),
    ));
    $assetOmissionDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($assetOmissionResult);
    $assetReasons = $assetOmissionDiagnostics['images']['asset_node_reason_categories'] ?? array();
    $assetSignal = blocks_engine_figma_transformer_contract_artifact_quality_signal($assetOmissionResult, 'missing_render_assets');
    $assert(1 === ($assetOmissionDiagnostics['images']['node_refs'] ?? null), 'diagnostics-evidence-asset-node-ref-count');
    $assert(1 === ($assetReasons['no_archive_asset'] ?? null), 'diagnostics-evidence-asset-no-archive-reason');
    $assert('no_archive_asset' === ($assetOmissionDiagnostics['images']['asset_nodes'][0]['reason'] ?? null), 'diagnostics-evidence-asset-node-reason');
    $assert('no_archive_asset' === ($assetSignal['sample_nodes'][0]['reason'] ?? null), 'diagnostics-evidence-asset-signal-sample-reason');
    $assert(true === ($assetOmissionDiagnostics['images']['asset_nodes'][0]['emitted'] ?? null), 'diagnostics-evidence-asset-missing-render-node-emitted');
    $assert(0 === ($assetOmissionDiagnostics['artifact_quality']['summary']['source_loss_coverage']['domains']['images']['not_emitted_source_nodes'] ?? null), 'diagnostics-evidence-asset-missing-render-no-source-loss-gap');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $assetOmissionResult, 'source_loss_coverage_gap', 'diagnostics-evidence-asset-missing-render-no-source-loss-signal');

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
    $maskMetadataDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($maskMetadataResult);
    $maskEffectClipping = $maskMetadataDiagnostics['mask_effect_clipping'] ?? array();
    $assert(1 === ($maskEffectClipping['mask_node_count'] ?? null), 'diagnostics-evidence-mask-node-count');
    $assert(3 === ($maskEffectClipping['mask_metadata_node_count'] ?? null), 'diagnostics-evidence-mask-metadata-node-count');
    $assert(0 === ($maskEffectClipping['emitted_mask_source_node_count'] ?? null), 'diagnostics-evidence-emitted-mask-source-count');
    $assert(1 === ($maskEffectClipping['suppressed_mask_source_node_count'] ?? null), 'diagnostics-evidence-suppressed-mask-source-count');
    $assert(2 === ($maskEffectClipping['clips_content_node_count'] ?? null), 'diagnostics-evidence-mask-clips-content-count');
    $assert(1 === ($maskEffectClipping['by_mask_type']['ALPHA'] ?? null), 'diagnostics-evidence-mask-type-count');
    $assert(false === ($maskEffectClipping['sample_nodes'][0]['frame_mask_disabled'] ?? null), 'diagnostics-evidence-frame-mask-disabled-sample');
    $assert(true === ($maskEffectClipping['sample_nodes'][1]['is_mask'] ?? null), 'diagnostics-evidence-is-mask-sample');
    $assert('ALPHA' === ($maskEffectClipping['sample_nodes'][1]['type'] ?? null), 'diagnostics-evidence-mask-type-sample');
    $assert('diag:mask-source' === ($maskEffectClipping['suppressed_mask_source_nodes'][0]['node_id'] ?? null), 'diagnostics-evidence-suppressed-mask-source-sample');
    $assert('ALPHA' === ($maskEffectClipping['suppressed_mask_source_nodes'][0]['type'] ?? null), 'diagnostics-evidence-suppressed-mask-source-type-sample');
    $assert(true === ($maskEffectClipping['sample_nodes'][2]['is_clip'] ?? null), 'diagnostics-evidence-is-clip-sample');
    $maskMetadataHtml = blocks_engine_figma_transformer_contract_file_content($maskMetadataResult, 'index.html');
    $assert(! str_contains($maskMetadataHtml, 'data-figma-node-id="diag:mask-source"'), 'diagnostics-evidence-mask-source-not-emitted');
    $assert(0 === ($maskMetadataDiagnostics['vectors']['placeholders'] ?? null), 'diagnostics-evidence-mask-source-not-vector-placeholder');
    $assert(1 === ($maskMetadataDiagnostics['artifact_quality']['summary']['mask_nodes'] ?? null), 'diagnostics-evidence-artifact-summary-mask-nodes');
    $assert(3 === ($maskMetadataDiagnostics['artifact_quality']['summary']['mask_metadata_nodes'] ?? null), 'diagnostics-evidence-artifact-summary-mask-metadata-nodes');
    $assert(0 === ($maskMetadataDiagnostics['artifact_quality']['summary']['emitted_mask_source_nodes'] ?? null), 'diagnostics-evidence-artifact-summary-emitted-mask-source-nodes');
    $assert(1 === ($maskMetadataDiagnostics['artifact_quality']['summary']['suppressed_mask_source_nodes'] ?? null), 'diagnostics-evidence-artifact-summary-suppressed-mask-source-nodes');

    $simpleMaskCompositionResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Simple Mask Composition Fixture',
        'assets' => array(
            'mask-photo' => array('mime_type' => 'image/png', 'content' => 'mask photo'),
        ),
        'nodes' => array(
            array(
                'id'       => 'mask:frame',
                'type'     => 'FRAME',
                'name'     => 'Mask Frame',
                'width'    => 240,
                'height'   => 120,
                'children' => array(
                    array(
                        'id'           => 'mask:rect-source',
                        'type'         => 'RECTANGLE',
                        'name'         => 'Rounded Rect Mask',
                        'x'            => 20,
                        'y'            => 10,
                        'width'        => 80,
                        'height'       => 60,
                        'cornerRadius' => 12,
                        'isMask'       => true,
                        'maskType'     => 'ALPHA',
                    ),
                    array(
                        'id'         => 'mask:rect-photo',
                        'type'       => 'RECTANGLE',
                        'name'       => 'Rect Masked Photo',
                        'x'          => 0,
                        'y'          => 0,
                        'width'      => 120,
                        'height'     => 80,
                        'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'mask-photo')),
                    ),
                    array(
                        'id'       => 'mask:ellipse-source',
                        'type'     => 'ELLIPSE',
                        'name'     => 'Ellipse Mask',
                        'x'        => 150,
                        'y'        => 20,
                        'width'    => 60,
                        'height'   => 60,
                        'isMask'   => true,
                        'maskType' => 'ALPHA',
                    ),
                    array(
                        'id'         => 'mask:ellipse-photo',
                        'type'       => 'RECTANGLE',
                        'name'       => 'Ellipse Masked Photo',
                        'x'          => 140,
                        'y'          => 0,
                        'width'      => 80,
                        'height'     => 100,
                        'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'mask-photo')),
                    ),
                ),
            ),
        ),
    ));
    $simpleMaskCss = blocks_engine_figma_transformer_contract_file_content($simpleMaskCompositionResult, 'style.css');
    $simpleMaskHtml = blocks_engine_figma_transformer_contract_file_content($simpleMaskCompositionResult, 'index.html');
    $simpleMaskDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($simpleMaskCompositionResult);
    $simpleMaskClipping = $simpleMaskDiagnostics['mask_effect_clipping'] ?? array();
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $simpleMaskCss, '.figma-node-mask-rect-photo-rect-masked-photo', array('clip-path:inset(10px 20px 10px 20px round 12px)'), 'diagnostics-evidence-rect-mask-emits-clip-path');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $simpleMaskCss, '.figma-node-mask-ellipse-photo-ellipse-masked-photo', array('clip-path:ellipse(30px 30px at 40px 50px)'), 'diagnostics-evidence-ellipse-mask-emits-clip-path');
    $assert(! str_contains($simpleMaskHtml, 'data-figma-node-id="mask:rect-source"'), 'diagnostics-evidence-rect-mask-source-suppressed');
    $assert(! str_contains($simpleMaskHtml, 'data-figma-node-id="mask:ellipse-source"'), 'diagnostics-evidence-ellipse-mask-source-suppressed');
    $assert(2 === ($simpleMaskClipping['mask_node_count'] ?? null), 'diagnostics-evidence-simple-mask-node-count');
    $assert(2 === ($simpleMaskClipping['suppressed_mask_source_node_count'] ?? null), 'diagnostics-evidence-simple-mask-source-suppressed-count');

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
                            array('id' => 'diag:aggregation-home-image', 'type' => 'RECTANGLE', 'name' => 'Missing Home Asset', 'width' => 80, 'height' => 60, 'layout' => array('positioning' => 'absolute'), 'asset_id' => 'missing-home'),
                            array('id' => 'diag:aggregation-home-vector', 'type' => 'VECTOR', 'name' => 'Unsupported Home Vector', 'width' => 24, 'height' => 24),
                        ),
                    ),
                    array(
                        'id'       => 'diag:aggregation-about',
                        'type'     => 'FRAME',
                        'name'     => 'Aggregation About',
                        'width'    => 320,
                        'height'   => 180,
                        'children' => array(
                            array('id' => 'diag:aggregation-about-image', 'type' => 'RECTANGLE', 'name' => 'Missing About Asset', 'width' => 80, 'height' => 60, 'layout' => array('positioning' => 'absolute'), 'asset_id' => 'missing-about'),
                            array('id' => 'diag:aggregation-about-vector', 'type' => 'VECTOR', 'name' => 'Unsupported About Vector', 'width' => 24, 'height' => 24),
                        ),
                    ),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('diag:aggregation-home', 'diag:aggregation-about'), 'entry_frame_id' => 'diag:aggregation-home'));
    $multiPageDiagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($multiPageResult);
    blocks_engine_figma_transformer_contract_assert_diagnostic_envelope($assert, $multiPageDiagnostics, 'blocks-engine/figma-transformer/transform-diagnostics/v1', 'diagnostics-evidence-multi-page-envelope');
    $missingAssets = $multiPageDiagnostics['images']['missing_assets'] ?? array();
    $placeholderNodes = $multiPageDiagnostics['vectors']['placeholder_nodes'] ?? array();
    $assert('multi_page' === ($multiPageDiagnostics['scope'] ?? null), 'diagnostics-evidence-multi-page-scope');
    $assert(6 === ($multiPageDiagnostics['visual_node_map_summary']['visual_node_count'] ?? null), 'diagnostics-evidence-multi-page-visual-map-summary-count');
    $assert(3 === ($multiPageDiagnostics['visual_node_map_summary']['page_path_counts']['index.html'] ?? null), 'diagnostics-evidence-multi-page-visual-map-summary-home-count');
    $assert(3 === ($multiPageDiagnostics['visual_node_map_summary']['page_path_counts']['aggregation-about.html'] ?? null), 'diagnostics-evidence-multi-page-visual-map-summary-about-count');
    $assert(3 === ($multiPageDiagnostics['visual_node_map_summary']['source_page_index_counts'][0] ?? null), 'diagnostics-evidence-multi-page-visual-map-summary-source-page-zero');
    $assert(3 === ($multiPageDiagnostics['visual_node_map_summary']['source_page_index_counts'][1] ?? null), 'diagnostics-evidence-multi-page-visual-map-summary-source-page-one');
    $assert(2 === ($multiPageDiagnostics['images']['node_refs'] ?? null), 'diagnostics-evidence-multi-page-image-node-count');
    $assert(2 === count(is_array($missingAssets) ? $missingAssets : array()), 'diagnostics-evidence-multi-page-missing-asset-sample-count');
    $assert('index.html' === ($missingAssets[0]['page_path'] ?? null), 'diagnostics-evidence-multi-page-missing-asset-home-context');
    $assert('aggregation-about.html' === ($missingAssets[1]['page_path'] ?? null), 'diagnostics-evidence-multi-page-missing-asset-about-context');
    $assert(2 === ($multiPageDiagnostics['vectors']['placeholders'] ?? null), 'diagnostics-evidence-multi-page-vector-placeholder-count');
    $assert(2 === ($multiPageResult['metrics']['vector_placeholder_count'] ?? null), 'diagnostics-evidence-multi-page-vector-placeholder-result-metric');
    $assert(2 === count(is_array($placeholderNodes) ? $placeholderNodes : array()), 'diagnostics-evidence-multi-page-placeholder-sample-count');
    $assert('diag:aggregation-home-vector' === ($placeholderNodes[0]['node_id'] ?? null), 'diagnostics-evidence-multi-page-placeholder-home-node');
    $assert('aggregation-about.html' === ($placeholderNodes[1]['page_path'] ?? null), 'diagnostics-evidence-multi-page-placeholder-about-context');
    $assert(2 === ($multiPageDiagnostics['diagnostic_codes']['unsupported_vector_node_placeholder'] ?? null), 'diagnostics-evidence-multi-page-diagnostic-code-count');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $multiPageDiagnostics, array('decision_traces', 'schema'), 'blocks-engine/figma-transformer/decision-traces/v1', 'diagnostics-evidence-multi-page-decision-traces-schema');
    $assert(2 <= ($multiPageDiagnostics['decision_traces']['domain_counts']['positioning_context'] ?? 0), 'diagnostics-evidence-multi-page-decision-traces-positioning-aggregated');
    blocks_engine_figma_transformer_contract_assert_diagnostic_value($assert, $multiPageDiagnostics, array('layout', 'positional_parity', 'schema'), 'blocks-engine/figma-transformer/positional-parity/v1', 'diagnostics-evidence-multi-page-positional-parity-schema');
    $assert(2 <= ($multiPageDiagnostics['layout']['positional_parity']['root_stacking_trace_count'] ?? 0), 'diagnostics-evidence-multi-page-positional-stacking-aggregated');
    $assert('index.html' === ($multiPageDiagnostics['layout']['positional_parity']['decision_trace_samples'][0]['page_path'] ?? null), 'diagnostics-evidence-multi-page-positional-sample-context');
    $sourceLossCoverage = $multiPageDiagnostics['artifact_quality']['summary']['source_loss_coverage'] ?? array();
    $sourceLossSignal = blocks_engine_figma_transformer_contract_artifact_quality_signal($multiPageResult, 'source_loss_coverage_gap');
    $assert(2 === ($sourceLossCoverage['not_emitted_source_nodes'] ?? null), 'diagnostics-evidence-multi-page-source-loss-total-count');
    $assert(0 === ($sourceLossCoverage['domains']['images']['not_emitted_source_nodes'] ?? null), 'diagnostics-evidence-multi-page-source-loss-image-domain-count');
    $assert(2 === ($sourceLossCoverage['domains']['vectors']['not_emitted_source_nodes'] ?? null), 'diagnostics-evidence-multi-page-source-loss-vector-domain-count');
    $assert(0.5 === ($sourceLossCoverage['coverage_ratio'] ?? null), 'diagnostics-evidence-multi-page-source-loss-ratio');
    $assert(2 === ($sourceLossSignal['count'] ?? null), 'diagnostics-evidence-multi-page-source-loss-signal-count');
    $assert('fail' === ($multiPageDiagnostics['artifact_quality']['quality_status'] ?? null), 'diagnostics-evidence-multi-page-source-loss-quality-fail');
    blocks_engine_figma_transformer_contract_assert_quality_signal($assert, $multiPageResult, 'missing_render_assets', 'diagnostics-evidence-multi-page-source-loss-missing-assets-signal');
    blocks_engine_figma_transformer_contract_assert_quality_signal($assert, $multiPageResult, 'vector_placeholders', 'diagnostics-evidence-multi-page-source-loss-vector-signal');
}

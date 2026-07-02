<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_component_clone_emission_contract(callable $assert): void
{
    $result = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Component Clone Emission Fixture',
        'nodes' => array(
            array(
                'id'                  => 'clone-contract:root',
                'type'                => 'FRAME',
                'name'                => 'Clone contract root',
                'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 640, 'height' => 240),
                'children'            => array(
                    array(
                        'id'                        => 'clone-contract:emitted',
                        'type'                      => 'TEXT',
                        'name'                      => 'Emitted clone child',
                        'characters'                => 'Visible clone copy',
                        'figma_component_source_id' => 'component-source:emitted',
                        'absoluteBoundingBox'       => array('x' => 32, 'y' => 32, 'width' => 220, 'height' => 32),
                    ),
                    array(
                        'id'                             => 'clone-contract:hidden-geometry',
                        'type'                           => 'RECTANGLE',
                        'name'                           => 'Hidden geometry clone child',
                        'visible'                        => false,
                        '_component_source_clone_geometry' => true,
                        'absoluteBoundingBox'            => array('x' => 32, 'y' => 96, 'width' => 180, 'height' => 48),
                    ),
                ),
            ),
        ),
    ));

    $html = blocks_engine_figma_transformer_contract_file_content($result, 'index.html');
    $diagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($result);
    $components = is_array($diagnostics['components'] ?? null) ? $diagnostics['components'] : array();
    $qualitySignal = blocks_engine_figma_transformer_contract_artifact_quality_signal($result, 'component_clone_not_emitted');

    $assert(str_contains($html, 'data-figma-node-id="clone-contract:emitted"'), 'component-clone-emitted-child-html');
    $assert(! str_contains($html, 'data-figma-node-id="clone-contract:hidden-geometry"'), 'component-clone-hidden-child-suppressed-html');
    $assert(2 === ($components['clone_source_node_count'] ?? null), 'component-clone-source-count-includes-source-id-and-geometry');
    $assert(1 === ($components['emitted_clone_node_count'] ?? null), 'component-clone-emitted-count');
    $assert(1 === ($components['missing_emitted_clone_node_count'] ?? null), 'component-clone-missing-count');
    $assert(array('hidden' => 1) === ($components['omission_reason_counts'] ?? null), 'component-clone-omission-reason-counts');
    $assert(1 === ($qualitySignal['count'] ?? null), 'component-clone-quality-signal-count');
    $assert(array('hidden' => 1) === ($qualitySignal['omission_reason_counts'] ?? null), 'component-clone-quality-signal-reason-counts');

    $missing = is_array($components['missing_emitted_clone_nodes'] ?? null) ? $components['missing_emitted_clone_nodes'] : array();
    $sample = is_array($missing[0] ?? null) ? $missing[0] : array();
    $assert('clone-contract:hidden-geometry' === ($sample['node_id'] ?? null), 'component-clone-missing-sample-node-id');
    $assert('hidden' === ($sample['omission_reason'] ?? null), 'component-clone-missing-sample-reason');
    $assert(true === ($sample['component_clone_geometry'] ?? null), 'component-clone-missing-sample-geometry');
}

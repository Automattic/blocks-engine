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
                    array(
                        'id'                  => 'clone-contract:composed-parent',
                        'type'                => 'BOOLEAN_OPERATION',
                        'name'                => 'Composed logo mark',
                        'absoluteBoundingBox' => array('x' => 320, 'y' => 32, 'width' => 64, 'height' => 32),
                        'fillGeometry'        => array(array('path' => 'M0 0L64 0L64 32L0 32Z')),
                        'children'            => array(
                            array(
                                'id'                        => 'clone-contract:composed-child',
                                'type'                      => 'VECTOR',
                                'name'                      => 'Composed logo child',
                                'figma_component_source_id' => 'component-source:composed-child',
                                'absoluteBoundingBox'       => array('x' => 320, 'y' => 32, 'width' => 64, 'height' => 32),
                                'fillGeometry'              => array(array('path' => 'M0 0L64 0L64 32L0 32Z')),
                            ),
                        ),
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
    $assert(3 === ($components['clone_source_node_count'] ?? null), 'component-clone-source-count-includes-source-id-and-geometry');
    $assert(1 === ($components['emitted_clone_node_count'] ?? null), 'component-clone-emitted-count');
    $assert(2 === ($components['missing_emitted_clone_node_count'] ?? null), 'component-clone-missing-count');
    $assert(array('composed-into-parent' => 1, 'hidden' => 1) === ($components['omission_reason_counts'] ?? null), 'component-clone-omission-reason-counts');
    $assert(2 === ($qualitySignal['count'] ?? null), 'component-clone-quality-signal-count');
    $assert(array('composed-into-parent' => 1, 'hidden' => 1) === ($qualitySignal['omission_reason_counts'] ?? null), 'component-clone-quality-signal-reason-counts');

    $missing = is_array($components['missing_emitted_clone_nodes'] ?? null) ? $components['missing_emitted_clone_nodes'] : array();
    $hiddenSamples = array_values(array_filter($missing, static fn (array $node): bool => 'clone-contract:hidden-geometry' === ($node['node_id'] ?? null)));
    $sample = is_array($hiddenSamples[0] ?? null) ? $hiddenSamples[0] : array();
    $assert('clone-contract:hidden-geometry' === ($sample['node_id'] ?? null), 'component-clone-missing-sample-node-id');
    $assert('hidden' === ($sample['omission_reason'] ?? null), 'component-clone-missing-sample-reason');
    $assert(true === ($sample['component_clone_geometry'] ?? null), 'component-clone-missing-sample-geometry');
    $assert(180 === ($sample['width'] ?? null), 'component-clone-missing-sample-width');
    $assert(48 === ($sample['height'] ?? null), 'component-clone-missing-sample-height');
    $assert(8640 === ($sample['visible_area_px'] ?? null), 'component-clone-missing-sample-visible-area');

    $composedSamples = array_values(array_filter($missing, static fn (array $node): bool => 'clone-contract:composed-child' === ($node['node_id'] ?? null)));
    $composedSample = is_array($composedSamples[0] ?? null) ? $composedSamples[0] : array();
    $assert('composed-into-parent' === ($composedSample['omission_reason'] ?? null), 'component-clone-composed-child-reason');

    $sourceOffsetNormalized = (new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer())->normalize(array(
        'name'  => 'Component Clone Source Local Offset Fixture',
        'nodes' => array(
            array(
                'id'       => 'source-offset:page',
                'type'     => 'FRAME',
                'name'     => 'Page',
                'width'    => 640,
                'height'   => 480,
                'children' => array(
                    array(
                        'id'          => 'source-offset:instance',
                        'type'        => 'INSTANCE',
                        'name'        => 'Section instance',
                        'componentId' => 'source-offset:component',
                        'width'       => 300,
                        'height'      => 200,
                        'derivedSymbolData' => array(
                            array(
                                'guidPath'  => array('guids' => array('source-offset:body')),
                                'size'      => array('x' => 300, 'y' => 120),
                                'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 0, 'm10' => 0, 'm11' => 1, 'm12' => 0),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'id'          => 'source-offset:component',
                'type'        => 'SYMBOL',
                'name'        => 'Section component',
                'componentId' => 'source-offset:component',
                'width'       => 300,
                'height'      => 200,
                'children'    => array(
                    array(
                        'id'     => 'source-offset:heading',
                        'type'   => 'TEXT',
                        'name'   => 'Heading',
                        'text'   => 'Trending',
                        'width'  => 180,
                        'height' => 48,
                        'x'      => 0,
                        'y'      => 0,
                    ),
                    array(
                        'id'     => 'source-offset:body',
                        'type'   => 'FRAME',
                        'name'   => 'Body',
                        'width'  => 300,
                        'height' => 120,
                        'x'      => 0,
                        'y'      => 80,
                    ),
                ),
            ),
        ),
    ), array('frame_id' => 'source-offset:page'));
    $sourceOffsetClone = null;
    foreach ( is_array($sourceOffsetNormalized['node_map']['source-offset:instance']['children'] ?? null) ? $sourceOffsetNormalized['node_map']['source-offset:instance']['children'] : array() as $sourceOffsetChild ) {
        if ( is_array($sourceOffsetChild) && 'source-offset:body' === ($sourceOffsetChild['figma_component_source_id'] ?? null) ) {
            $sourceOffsetClone = $sourceOffsetChild;
            break;
        }
    }
    $sourceOffsetCloneBox = is_array($sourceOffsetClone) && is_array($sourceOffsetClone['box'] ?? null) ? $sourceOffsetClone['box'] : array();
    $assert(80.0 === ($sourceOffsetCloneBox['y'] ?? null), 'component-clone-source-local-offset-preserved');

    $offsetResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Component Clone Source Refresh Offset Fixture',
            'nodes' => array(
                array(
                    'id'                  => 'offset-source:section',
                    'type'                => 'COMPONENT',
                    'name'                => 'Offset source section',
                    'absoluteBoundingBox' => array('x' => 1000, 'y' => 500, 'width' => 320, 'height' => 160),
                    'children'            => array(
                        array(
                            'id'                  => 'offset-source:section/header',
                            'type'                => 'TEXT',
                            'name'                => 'Offset header',
                            'characters'          => 'Header',
                            'absoluteBoundingBox' => array('x' => 1000, 'y' => 500, 'width' => 320, 'height' => 40),
                        ),
                        array(
                            'id'                  => 'offset-source:section/content',
                            'type'                => 'TEXT',
                            'name'                => 'Offset content',
                            'characters'          => 'Content',
                            'absoluteBoundingBox' => array('x' => 1000, 'y' => 580, 'width' => 320, 'height' => 40),
                        ),
                    ),
                ),
                array(
                    'id'       => 'offset-page',
                    'type'     => 'FRAME',
                    'name'     => 'Offset page',
                    'width'    => 400,
                    'height'   => 240,
                    'children' => array(
                        array(
                            'id'          => 'offset-instance:section',
                            'type'        => 'INSTANCE',
                            'name'        => 'Offset placed section',
                            'componentId' => 'offset-source:section',
                            'x'           => 20,
                            'y'           => 30,
                            'width'       => 320,
                            'height'      => 160,
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'offset-page')
    );

    $offsetCss = blocks_engine_figma_transformer_contract_file_content($offsetResult, 'style.css');
    $offsetHeader = blocks_engine_figma_transformer_contract_find_visual_node($offsetResult, 'offset-instance:section/offset-source:section/header');
    $offsetContent = blocks_engine_figma_transformer_contract_find_visual_node($offsetResult, 'offset-instance:section/offset-source:section/content');

    $assert(str_contains($offsetCss, '.offset-header{width:320px;height:40px;position:absolute;left:0px;top:0px}'), 'component-clone-source-refresh-header-keeps-local-y-zero-css');
    $assert(str_contains($offsetCss, '.offset-content{width:320px;height:40px;position:absolute;left:0px;top:80px}'), 'component-clone-source-refresh-content-keeps-local-y-offset-css');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $offsetHeader, array('x' => 20.0, 'y' => 30.0, 'width' => 320.0, 'height' => 40.0), 'component-clone-source-refresh-header-visual-offset');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $offsetContent, array('x' => 20.0, 'y' => 110.0, 'width' => 320.0, 'height' => 40.0), 'component-clone-source-refresh-content-visual-offset');
}

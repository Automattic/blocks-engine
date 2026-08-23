<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_component_clone_emission_contract(callable $assert): void
{
    $cloneGeometry = new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ComponentSourceCloneGeometry();
    $farCloneDecision = $cloneGeometry->decideGeometrySource(
        array(
            'figma_component_source_id' => 'component-source:far',
            'box'                       => array('x' => 640, 'y' => 40, 'width' => 120, 'height' => 40, 'coordinate_space' => 'local'),
        ),
        array(
            'box' => array('x' => 180, 'y' => 40, 'width' => 120, 'height' => 40, 'coordinate_space' => 'local'),
        )
    );
    $assert(false === $farCloneDecision->useRefreshedGeometry, 'component-clone-source-decision-preserves-near-clone-x');
    $assert('clone-geometry-preserved' === $farCloneDecision->reason, 'component-clone-source-decision-preserves-near-clone-reason');

    $staleCloneDecision = $cloneGeometry->decideGeometrySource(
        array(
            'figma_component_source_id' => 'component-source:stale',
            'box'                       => array('x' => 1640, 'y' => 40, 'width' => 120, 'height' => 40, 'coordinate_space' => 'local'),
        ),
        array(
            'box' => array('x' => 180, 'y' => 40, 'width' => 120, 'height' => 40, 'coordinate_space' => 'local'),
        )
    );
    $assert(true === $staleCloneDecision->useRefreshedGeometry, 'component-clone-source-decision-refreshes-far-clone-x');
    $assert('clone-box-x-far-from-refreshed' === $staleCloneDecision->reason, 'component-clone-source-decision-refreshes-far-clone-reason');

    $stalePageLocalCloneDecision = $cloneGeometry->decideGeometrySource(
        array(
            'figma_component_source_id' => 'component-source:page-local-stale',
            'x'                         => 1180,
            'y'                         => 72,
            'box'                       => array('x' => 1774, 'y' => 2387, 'width' => 225, 'height' => 48, 'coordinate_space' => 'local', 'local_origin' => 'page'),
        ),
        array(
            'box' => array('x' => 1180, 'y' => 72, 'width' => 225, 'height' => 48, 'coordinate_space' => 'local'),
        )
    );
    $assert(true === $stalePageLocalCloneDecision->useRefreshedGeometry, 'component-clone-source-decision-refreshes-box-scalar-conflict');
    $assert('clone-box-x-disagrees-with-scalar' === $stalePageLocalCloneDecision->reason, 'component-clone-source-decision-refreshes-box-scalar-conflict-reason');

    $scalarPositioning = new Automattic\BlocksEngine\FigmaTransformer\Html\CssPositioningResolver(
        new Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier(),
        new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlValueFormatter()
    );
    $scalarStyles = $scalarPositioning->styles(
        array('x' => 1774, 'y' => 2387, 'width' => 225, 'height' => 48, 'coordinate_space' => 'local', 'local_origin' => 'page'),
        array('freeform' => true),
        array('box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 145), 'layout' => array('freeform' => true)),
        array('figma_component_source_id' => 'component-source:page-local-css', 'x' => 1180, 'y' => 72)
    );
    $assert(in_array('left:1180px', $scalarStyles, true), 'component-clone-page-local-css-uses-clone-scalar-x');
    $assert(in_array('top:72px', $scalarStyles, true), 'component-clone-page-local-css-uses-clone-scalar-y');
    $scalarEffectiveOffsets = $scalarPositioning->effectiveOffsets(
        array('x' => 1774, 'y' => 2387, 'width' => 225, 'height' => 48, 'coordinate_space' => 'local', 'local_origin' => 'page'),
        array('box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 145), 'layout' => array('freeform' => true)),
        array('figma_component_source_id' => 'component-source:page-local-css', 'x' => 1180, 'y' => 72)
    );
    $assert(array('x' => 1180.0, 'y' => 72.0) === $scalarEffectiveOffsets, 'component-clone-effective-offsets-use-clone-scalar');

    $headerChromePositioning = new Automattic\BlocksEngine\FigmaTransformer\Html\CssPositioningResolver(
        new Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier(),
        new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlValueFormatter()
    );
    $headerChromeCtaStyles = $headerChromePositioning->styles(
        array('x' => 1180, 'y' => 72, 'width' => 225, 'height' => 48),
        array(),
        array('name' => 'Header', 'box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 145), 'layout' => array('freeform' => true)),
        array('id' => 'header:cta', 'type' => 'INSTANCE', 'name' => 'Button One')
    );
    $assert(in_array('right:35px', $headerChromeCtaStyles, true), 'component-clone-header-cta-pins-to-right-edge');
    $assert(! in_array('left:1180px', $headerChromeCtaStyles, true), 'component-clone-header-cta-drops-stale-left-edge');
    $headerChromeStripStyles = $headerChromePositioning->styles(
        array('x' => 264, 'y' => 0, 'width' => 1176, 'height' => 53),
        array(),
        array('name' => 'Header', 'box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 145), 'layout' => array('freeform' => true)),
        array('id' => 'header:strip', 'type' => 'RECTANGLE', 'name' => 'Top info strip')
    );
    $assert(array('left:264px', 'right:0px', 'width:auto', 'top:0px') === $headerChromeStripStyles, 'component-clone-header-strip-stretches-to-right-edge');

    $absoluteSourceDecision = $cloneGeometry->decideGeometrySource(
        array(
            'figma_component_source_id' => 'component-source:absolute',
            'box'                       => array('x' => 1640, 'y' => 40, 'width' => 120, 'height' => 40, 'coordinate_space' => 'local'),
        ),
        array(
            'box' => array('x' => 180, 'y' => 40, 'width' => 120, 'height' => 40, 'coordinate_space' => 'absolute'),
        )
    );
    $assert(false === $absoluteSourceDecision->useRefreshedGeometry, 'component-clone-source-decision-rejects-absolute-source');
    $assert('refreshed-box-not-parent-local' === $absoluteSourceDecision->reason, 'component-clone-source-decision-rejects-absolute-source-reason');

    $mixedComponent = array(
        'id'       => 'component-source:mixed-control',
        'type'     => 'SYMBOL',
        'box'      => array('x' => 0, 'y' => 0, 'width' => 200, 'height' => 60, 'coordinate_space' => 'local'),
        'layout'   => array('display' => 'flex', 'flex_direction' => 'row', 'align_items' => 'center', 'gap' => 12),
        'children' => array(
            array('id' => 'component-source:mixed-label', 'type' => 'TEXT', 'text' => 'Contact', 'box' => array('x' => 24, 'y' => 18, 'width' => 100, 'height' => 24, 'coordinate_space' => 'local')),
            array('id' => 'component-source:mixed-icon', 'type' => 'VECTOR', 'box' => array('x' => 136, 'y' => 18, 'width' => 24, 'height' => 24, 'coordinate_space' => 'local')),
        ),
    );
    $mixedClone = array(
        'id'                              => 'instance:mixed-control',
        'type'                            => 'INSTANCE',
        'figma_component_source_id'       => 'component-source:mixed-control',
        'box'                             => array('x' => 20, 'y' => 20, 'width' => 400, 'height' => 72, 'coordinate_space' => 'local'),
        'layout'                          => array('display' => null, 'flex_direction' => null),
        '_component_source_clone_scale'   => array('x' => 2, 'y' => 1.2),
    );
    $mixedMerged = $cloneGeometry->mergeRefreshed($mixedClone, $mixedComponent, 'component-source:mixed-control');
    $assert('flex' === ($mixedMerged['layout']['display'] ?? null) && 'row' === ($mixedMerged['layout']['flex_direction'] ?? null), 'component-clone-mixed-control-inherits-source-auto-layout');
    $assert(100 === ($mixedMerged['children'][0]['box']['width'] ?? null) && 24 === ($mixedMerged['children'][1]['box']['width'] ?? null), 'component-clone-mixed-control-does-not-scale-text-or-icon-children');

    $vectorComponent = array(
        'id'       => 'component-source:vector-icon',
        'type'     => 'SYMBOL',
        'box'      => array('x' => 0, 'y' => 0, 'width' => 24, 'height' => 24, 'coordinate_space' => 'local'),
        'children' => array(
            array('id' => 'component-source:vector-path', 'type' => 'VECTOR', 'box' => array('x' => 0, 'y' => 0, 'width' => 24, 'height' => 24, 'coordinate_space' => 'local')),
        ),
    );
    $vectorClone = array(
        'id'                            => 'instance:vector-icon',
        'type'                          => 'INSTANCE',
        'figma_component_source_id'     => 'component-source:vector-icon',
        'box'                           => array('x' => 0, 'y' => 0, 'width' => 48, 'height' => 48, 'coordinate_space' => 'local'),
        '_component_source_clone_scale' => array('x' => 2, 'y' => 2),
    );
    $vectorMerged = $cloneGeometry->mergeRefreshed($vectorClone, $vectorComponent, 'component-source:vector-icon');
    $assert(48.0 === ($vectorMerged['children'][0]['box']['width'] ?? null) && 48.0 === ($vectorMerged['children'][0]['box']['height'] ?? null), 'component-clone-vector-only-control-still-scales-vector-children');

    $unresolvedControlResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Unresolved Mixed Control Fixture',
        'nodes' => array(
            array(
                'id'       => 'unresolved-control:button',
                'type'     => 'INSTANCE',
                'name'     => 'Secondary Button',
                'width'    => 520,
                'height'   => 72,
                'children' => array(
                    array('id' => 'unresolved-control:label', 'type' => 'TEXT', 'name' => 'Label', 'text' => 'WhatsApp', 'x' => 100, 'y' => 12, 'width' => 176, 'height' => 48),
                    array(
                        'id'       => 'unresolved-control:icon',
                        'type'     => 'INSTANCE',
                        'name'     => 'WhatsApp icon',
                        'x'        => 178,
                        'y'        => -2,
                        'width'    => 77,
                        'height'   => 77,
                        '_component_source_clone_geometry' => true,
                        'figma_box' => array('width' => 77, 'height' => 77, 'coordinate_space' => 'local'),
                        'children' => array(
                            array('id' => 'unresolved-control:path', 'type' => 'VECTOR', 'name' => 'Vector', 'width' => 77, 'height' => 77, 'pathData' => 'M0 0H77V77H0Z'),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $unresolvedControlHtml = blocks_engine_figma_transformer_contract_file_content($unresolvedControlResult, 'index.html');
    $unresolvedControlCss = blocks_engine_figma_transformer_contract_file_content($unresolvedControlResult, 'style.css');
    $assert(str_contains($unresolvedControlHtml, '<button class="figma-node-unresolved-control-button-secondary-button"'), 'component-clone-unresolved-named-control-remains-semantic-button');
    $assert(str_contains($unresolvedControlCss, 'button{appearance:none;font:inherit;color:inherit;background:none;border-width:0;border-style:none;padding:0}'), 'component-clone-semantic-button-resets-user-agent-chrome');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $unresolvedControlCss, '.figma-node-unresolved-control-button-secondary-button', array('display:flex', 'flex-direction:row', 'justify-content:center', 'align-items:center'), 'component-clone-unresolved-control-infers-flow-row');
    $assert(str_contains($unresolvedControlCss, '>.figma-node-unresolved-control-icon-whatsapp-icon{position:relative;left:auto;right:auto;top:auto;bottom:auto;width:36px;height:36px'), 'component-clone-unresolved-control-clamps-visual-to-cross-axis');

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
                    array(
                        'id'                        => 'clone-contract:mask-source',
                        'type'                      => 'RECTANGLE',
                        'name'                      => 'Mask source clone child',
                        'figma_component_source_id' => 'component-source:mask-source',
                        'isMask'                    => true,
                        'absoluteBoundingBox'       => array('x' => 424, 'y' => 32, 'width' => 32, 'height' => 32),
                    ),
                ),
            ),
        ),
    ));

    $html = blocks_engine_figma_transformer_contract_file_content($result, 'index.html');
    $diagnostics = blocks_engine_figma_transformer_contract_transform_diagnostics($result);
    $components = is_array($diagnostics['components'] ?? null) ? $diagnostics['components'] : array();

    $assert(str_contains($html, 'data-figma-node-id="clone-contract:emitted"'), 'component-clone-emitted-child-html');
    $assert(! str_contains($html, 'data-figma-node-id="clone-contract:hidden-geometry"'), 'component-clone-hidden-child-suppressed-html');
    $assert(! str_contains($html, 'data-figma-node-id="clone-contract:mask-source"'), 'component-clone-mask-source-suppressed-html');
    $assert(4 === ($components['clone_source_node_count'] ?? null), 'component-clone-source-count-includes-source-id-and-geometry');
    $assert(1 === ($components['emitted_clone_node_count'] ?? null), 'component-clone-emitted-count');
    $assert(0 === ($components['missing_emitted_clone_node_count'] ?? null), 'component-clone-missing-count');
    $assert(3 === ($components['intentionally_suppressed_clone_node_count'] ?? null), 'component-clone-intentionally-suppressed-count');
    $assert(array() === ($components['omission_reason_counts'] ?? null), 'component-clone-omission-reason-counts');
    $assert(array('composed-into-parent' => 1, 'hidden' => 1, 'mask-source' => 1) === ($components['intentional_suppression_reason_counts'] ?? null), 'component-clone-intentional-suppression-reason-counts');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $result, 'component_clone_not_emitted', 'component-clone-no-quality-signal-for-hidden-scaffold');
    $assert(1 === ($diagnostics['decision_traces']['reason_counts']['hidden_descendant_suppressed'] ?? null), 'component-clone-hidden-decision-trace-reason');
    $assert(1 === ($diagnostics['decision_traces']['reason_counts']['mask_source_suppressed'] ?? null), 'component-clone-mask-decision-trace-reason');

    $intentional = is_array($components['intentionally_suppressed_clone_nodes'] ?? null) ? $components['intentionally_suppressed_clone_nodes'] : array();
    $hiddenSamples = array_values(array_filter($intentional, static fn (array $node): bool => 'clone-contract:hidden-geometry' === ($node['node_id'] ?? null)));
    $sample = is_array($hiddenSamples[0] ?? null) ? $hiddenSamples[0] : array();
    $assert('clone-contract:hidden-geometry' === ($sample['node_id'] ?? null), 'component-clone-missing-sample-node-id');
    $assert('hidden' === ($sample['omission_reason'] ?? null), 'component-clone-missing-sample-reason');
    $assert(true === ($sample['component_clone_geometry'] ?? null), 'component-clone-missing-sample-geometry');
    $assert(180 === ($sample['width'] ?? null), 'component-clone-missing-sample-width');
    $assert(48 === ($sample['height'] ?? null), 'component-clone-missing-sample-height');
    $assert(8640 === ($sample['visible_area_px'] ?? null), 'component-clone-missing-sample-visible-area');

    $composedSamples = array_values(array_filter($intentional, static fn (array $node): bool => 'clone-contract:composed-child' === ($node['node_id'] ?? null)));
    $composedSample = is_array($composedSamples[0] ?? null) ? $composedSamples[0] : array();
    $assert('composed-into-parent' === ($composedSample['omission_reason'] ?? null), 'component-clone-composed-child-reason');

    $maskSamples = array_values(array_filter($intentional, static fn (array $node): bool => 'clone-contract:mask-source' === ($node['node_id'] ?? null)));
    $maskSample = is_array($maskSamples[0] ?? null) ? $maskSamples[0] : array();
    $assert('mask-source' === ($maskSample['omission_reason'] ?? null), 'component-clone-mask-source-intentional-reason');

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

    $sourceOffsetPreservationDiagnostics = array_values(array_filter(
        is_array($sourceOffsetNormalized['diagnostics'] ?? null) ? $sourceOffsetNormalized['diagnostics'] : array(),
        static fn (array $diagnostic): bool => 'figma_component_clone_transform_override_source_preserved' === ($diagnostic['code'] ?? null)
    ));
    $sourceOffsetPreservationDiagnostic = is_array($sourceOffsetPreservationDiagnostics[0] ?? null) ? $sourceOffsetPreservationDiagnostics[0] : array();
    $assert('source-offset:body' === (($sourceOffsetPreservationDiagnostic['context']['source_node_id'] ?? null) ?: ($sourceOffsetPreservationDiagnostic['context']['node_id'] ?? null)), 'component-clone-source-local-offset-preservation-diagnostic-source-id');
    $assert(array('y') === ($sourceOffsetPreservationDiagnostic['context']['preserved_dimensions'] ?? null), 'component-clone-source-local-offset-preservation-diagnostic-dimensions');
    $assert(array('m00' => 1.0, 'm01' => 0.0, 'm02' => 0.0, 'm10' => 0.0, 'm11' => 1.0, 'm12' => 0.0) === ($sourceOffsetPreservationDiagnostic['context']['raw_override_fields']['transform'] ?? null), 'component-clone-source-local-offset-preservation-diagnostic-raw-transform');
    $assert(array('x' => 300.0, 'y' => 120.0) === ($sourceOffsetPreservationDiagnostic['context']['raw_override_fields']['size'] ?? null), 'component-clone-source-local-offset-preservation-diagnostic-raw-size');
    $assert(80.0 === ($sourceOffsetPreservationDiagnostic['context']['source_box']['y'] ?? null), 'component-clone-source-local-offset-preservation-diagnostic-source-y');
    $assert(0.0 === ($sourceOffsetPreservationDiagnostic['context']['override_box']['y'] ?? null), 'component-clone-source-local-offset-preservation-diagnostic-override-y');

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

    $fontCloneResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Component Clone Font Preservation Fixture',
            'nodes' => array(
                array(
                    'id'       => 'font-clone:newsletter-component',
                    'type'     => 'COMPONENT',
                    'name'     => 'Newsletter card component',
                    'width'    => 420,
                    'height'   => 120,
                    'children' => array(
                        array(
                            'id'         => 'font-clone:newsletter-heading',
                            'type'       => 'TEXT',
                            'name'       => 'Card heading',
                            'characters' => 'Default newsletter heading',
                            'width'      => 260,
                            'height'     => 48,
                            'fontName'   => array('family' => 'Barlow Condensed', 'style' => 'Bold'),
                            'fontSize'   => 32,
                        ),
                    ),
                ),
                array(
                    'id'       => 'font-clone:read-next-component',
                    'type'     => 'COMPONENT',
                    'name'     => 'Read next header component',
                    'width'    => 420,
                    'height'   => 120,
                    'children' => array(
                        array(
                            'id'         => 'font-clone:read-next-heading',
                            'type'       => 'TEXT',
                            'name'       => 'Read next title',
                            'characters' => 'Default read next',
                            'width'      => 240,
                            'height'     => 48,
                            'fontName'   => array('family' => 'Barlow Condensed', 'style' => 'Bold'),
                            'fontSize'   => 32,
                        ),
                    ),
                ),
                array(
                    'id'       => 'font-clone:page',
                    'type'     => 'FRAME',
                    'name'     => 'Article page',
                    'width'    => 960,
                    'height'   => 360,
                    'children' => array(
                        array(
                            'id'                  => 'font-clone:newsletter-instance',
                            'type'                => 'INSTANCE',
                            'name'                => 'Newsletter card',
                            'componentId'         => 'font-clone:newsletter-component',
                            'width'               => 420,
                            'height'              => 120,
                            'derivedSymbolData'   => array(
                                'symbolOverrides' => array(
                                    'font-clone:newsletter-heading' => array('textData' => array('characters' => 'Get the newsletter')),
                                ),
                            ),
                        ),
                        array(
                            'id'                  => 'font-clone:read-next-instance',
                            'type'                => 'INSTANCE',
                            'name'                => 'Read next section',
                            'componentId'         => 'font-clone:read-next-component',
                            'width'               => 420,
                            'height'              => 120,
                            'y'                   => 160,
                            'derivedSymbolData'   => array(
                                'symbolOverrides' => array(
                                    'font-clone:read-next-heading' => array('textData' => array('characters' => 'Read next')),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'font-clone:page')
    );
    $fontCloneHtml = blocks_engine_figma_transformer_contract_file_content($fontCloneResult, 'index.html');
    $fontCloneCss = blocks_engine_figma_transformer_contract_file_content($fontCloneResult, 'style.css');

    $assert(str_contains($fontCloneHtml, '<h2 class="figma-node-font-clone-newsletter-instance-font-clone-newsletter-heading-card-heading" data-figma-node-id="font-clone:newsletter-instance/font-clone:newsletter-heading"'), 'component-clone-newsletter-text-only-override-keeps-heading-tag');
    $assert(str_contains($fontCloneHtml, '<h2 class="figma-node-font-clone-read-next-instance-font-clone-read-next-heading-read-next-title" data-figma-node-id="font-clone:read-next-instance/font-clone:read-next-heading"'), 'component-clone-read-next-text-only-override-keeps-heading-tag');
    $assert(str_contains($fontCloneCss, '.figma-node-font-clone-newsletter-instance-font-clone-newsletter-heading-card-heading{') && str_contains($fontCloneCss, 'font-family:"Barlow Condensed", sans-serif;font-size:32px;font-weight:700'), 'component-clone-newsletter-text-only-override-keeps-font');
    $assert(str_contains($fontCloneCss, '.figma-node-font-clone-read-next-instance-font-clone-read-next-heading-read-next-title{') && str_contains($fontCloneCss, 'font-family:"Barlow Condensed", sans-serif;font-size:32px;font-weight:700'), 'component-clone-read-next-text-only-override-keeps-font');
}

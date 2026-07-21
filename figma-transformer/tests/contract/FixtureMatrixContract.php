<?php

declare(strict_types=1);

require_once __DIR__ . '/../../scripts/figma-fixture-matrix-quality.php';

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_fixture_matrix_contract(callable $assert): void
{
    $matrixFixtureDir = sys_get_temp_dir() . '/figma-fixture-matrix-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($matrixFixtureDir, 0777, true);
    file_put_contents($matrixFixtureDir . '/alias.fig', 'placeholder fig fixture');
    file_put_contents($matrixFixtureDir . '/explicit.fig', 'explicit fig fixture');

    $matrixSelection = matrix_select_frame_ids(array(
        'candidates' => array(
            array(
                'id'         => 'frame:title-card',
                'name'       => 'Title Card',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'dev_status' => 'ready_for_dev',
                'score'      => 2000,
                'width'      => 2238,
                'height'     => 291,
                'text_count' => 1,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 2,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:single-desktop',
                'name'       => 'Blog Post - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'single',
                'score'      => 650,
                'width'      => 1440,
                'height'     => 8400,
                'text_count' => 31,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
        ),
    ), 5);

    $wideRootSelection = matrix_select_frame_ids(array(
        'candidates' => array(
            array(
                'id'         => 'frame:fisiostetic-home',
                'name'       => 'Fisiostetic home page',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 2095,
                'height'     => 6200,
                'text_count' => 120,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Fisiostetic'),
            ),
            array(
                'id'         => 'frame:pricing-section',
                'name'       => 'Pricing',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 900,
                'width'      => 1440,
                'height'     => 1200,
                'text_count' => 20,
                'parent'     => array('type' => 'SECTION'),
                'page'       => array('name' => 'Fisiostetic'),
            ),
        ),
    ), 1);

    $componentComposedFrontPageSelection = matrix_select_frame_ids(array(
        'candidates' => array(
            array(
                'id'         => 'frame:archive-template',
                'name'       => 'News blog with various grids',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'archive',
                'score'      => 722,
                'width'      => 1440,
                'height'     => 3328,
                'text_count' => 28,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Templates'),
            ),
            array(
                'id'         => 'frame:screenshot',
                'name'       => 'screenshot',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 1200,
                'width'      => 1200,
                'height'     => 900,
                'text_count' => 12,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Templates'),
            ),
            array(
                'id'         => 'frame:component-front-page',
                'name'       => 'Business Homepage',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 480,
                'width'      => 1440,
                'height'     => 3875,
                'text_count' => 0,
                'parent'     => array('type' => 'SECTION'),
                'page'       => array('name' => 'Patterns'),
            ),
            array(
                'id'         => 'frame:comments-utility',
                'name'       => 'Comments',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 900,
                'width'      => 1440,
                'height'     => 1800,
                'text_count' => 3,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Templates'),
            ),
        ),
    ), 2);

    $canonicalTemplateSelectionInspection = array(
        'candidates' => array(
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:single-desktop',
                'name'       => 'Blog Post - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'single',
                'score'      => 650,
                'width'      => 1440,
                'height'     => 8400,
                'text_count' => 80,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:page-desktop',
                'name'       => 'Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 620,
                'width'      => 1440,
                'height'     => 3200,
                'text_count' => 30,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:archive-desktop',
                'name'       => 'Archive - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'archive',
                'score'      => 440,
                'width'      => 1440,
                'height'     => 3600,
                'text_count' => 36,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:404-desktop',
                'name'       => '404 Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => '404',
                'score'      => 350,
                'width'      => 1440,
                'height'     => 1800,
                'text_count' => 12,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:contact-desktop',
                'name'       => 'Contact - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 300,
                'width'      => 1440,
                'height'     => 1800,
                'text_count' => 12,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
        ),
    );
    $canonicalTemplateSelection = matrix_select_frame_ids($canonicalTemplateSelectionInspection, 5);
    $canonicalTemplateOmissions = matrix_omitted_page_candidate_records($canonicalTemplateSelectionInspection, $canonicalTemplateSelection);
    $coveredBucketOmissions = matrix_omitted_page_candidate_records(array(
        'candidates' => array(
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups'),
            ),
            array(
                'id'         => 'frame:home-alt',
                'name'       => 'Home Page - Alternate Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 450,
                'width'      => 1200,
                'height'     => 5200,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups'),
            ),
        ),
    ), array('frame:home-desktop'));
    $coveredResponsiveVariantOmissions = matrix_omitted_page_candidate_records(array(
        'candidates' => array(
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups'),
            ),
            array(
                'id'         => 'frame:home-tablet',
                'name'       => 'Home Page - Tablet',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 450,
                'width'      => 1024,
                'height'     => 5200,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups'),
                'responsive_siblings' => array(
                    array('id' => 'frame:home-desktop', 'device_hint' => 'desktop'),
                ),
            ),
        ),
    ), array('frame:home-desktop'));
    $pageCapOnlyOmissions = matrix_omitted_page_candidate_records(array(
        'candidates' => array(
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups'),
            ),
            array(
                'id'         => 'frame:single-desktop',
                'name'       => 'Blog Post - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'single',
                'score'      => 650,
                'width'      => 1440,
                'height'     => 8400,
                'text_count' => 80,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups'),
            ),
        ),
    ), array('frame:home-desktop'));
    $matrixVisualReadiness = matrix_fixture_visual_readiness(array(
        'id'                 => 'risk-fixture',
        'selected_frame_ids' => array('frame:home'),
        'omitted_page_candidates' => array(array('id' => 'frame:contact')),
        'quality_summary'    => array(
            'fixed_width_without_responsive_override_count' => 2,
            'fixed_width_over_desktop_uncovered_count' => 1,
            'desktop_canvas_without_responsive_breakpoints' => true,
            'large_absolute_offset_count' => 3,
            'large_css_offset_count' => 1,
            'missing_emitted_text_nodes' => 4,
            'layout_mismatch_count' => 2,
            'missing_asset_nodes' => 1,
            'fallback_prone_form_island_count' => 1,
            'fallback_prone_input_island_count' => 2,
            'link_targets_unresolved' => 1,
            'vector_placeholders' => 2,
            'vector_decode_coverage_ratio' => 0.8,
            'html_artifact' => array(
                'breakpoint_override_leak_count' => 1,
            ),
        ),
    ));
    $cappedRouteOmissions = array();
    for ( $routeIndex = 4; $routeIndex <= 54; ++$routeIndex ) {
        $cappedRouteOmissions[] = array(
            'id' => 'frame:route-' . $routeIndex,
            'reason' => 'outside_page_cap',
        );
    }
    $cappedSelectionVisualReadiness = matrix_fixture_visual_readiness(array(
        'id' => 'fifty-four-route-fixture',
        'selected_frame_ids' => array('frame:route-1', 'frame:route-2', 'frame:route-3'),
        'omitted_page_candidates' => $cappedRouteOmissions,
        'quality_summary' => array(),
    ));
    $coveredRouteVisualReadiness = matrix_fixture_visual_readiness(array(
        'id' => 'covered-route-fixture',
        'selected_frame_ids' => array('frame:home'),
        'omitted_page_candidates' => array(
            array('id' => 'frame:home-tablet', 'reason' => 'covered_by_selected_route_bucket'),
            array('id' => 'frame:home-mobile', 'reason' => 'covered_by_selected_responsive_variant'),
        ),
        'quality_summary' => array(),
    ));
    $domBoxQuality = matrix_analyze_dom_box_report(array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'entrypoints' => array(
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 1440, 'height' => 900, 'device_scale_factor' => 1),
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'stylesheet_status' => array('body_margin' => '0px', 'body_margin_reset' => true),
                'elements' => array(
                    array(
                        'node_id' => 'frame:root',
                        'node_name' => 'Root',
                        'selector' => 'main[data-figma-node-id="frame:root"]',
                        'tag' => 'main',
                        'boundingClientRect' => array('left' => 0, 'right' => 1488, 'top' => 0, 'bottom' => 100, 'width' => 1488, 'height' => 100),
                        'text_metrics' => array('scroll_width' => 1500, 'client_width' => 1440),
                    ),
                    array(
                        'node_id' => 'frame:collapsed-image',
                        'node_name' => 'Collapsed image',
                        'selector' => 'img[data-figma-node-id="frame:collapsed-image"]',
                        'tag' => 'img',
                        'boundingClientRect' => array('left' => 16, 'right' => 16, 'top' => 112, 'bottom' => 112, 'width' => 0, 'height' => 0),
                    ),
                    array(
                        'node_id' => 'frame:offscreen',
                        'node_name' => 'Offscreen',
                        'selector' => 'section[data-figma-node-id="frame:offscreen"]',
                        'tag' => 'section',
                        'boundingClientRect' => array('left' => -42, 'right' => 20, 'top' => 130, 'bottom' => 220, 'width' => 62, 'height' => 90),
                    ),
                    array(
                        'node_id' => 'frame:later',
                        'node_name' => 'Later section',
                        'selector' => 'section[data-figma-node-id="frame:later"]',
                        'tag' => 'section',
                        'boundingClientRect' => array('left' => 20, 'right' => 300, 'top' => 1300, 'bottom' => 1400, 'width' => 280, 'height' => 100),
                    ),
                    array(
                        'node_id' => '',
                        'node_name' => 'Missing id',
                        'selector' => 'div',
                        'tag' => 'div',
                        'boundingClientRect' => array('left' => 20, 'right' => 100, 'top' => 1420, 'bottom' => 1500, 'width' => 80, 'height' => 80),
                    ),
                ),
                'unidentified_elements' => array(
                    array(
                        'selector' => 'aside',
                        'tag' => 'aside',
                        'boundingClientRect' => array('left' => 20, 'right' => 100, 'top' => 1520, 'bottom' => 1600, 'width' => 80, 'height' => 80),
                    ),
                ),
            ),
        ),
    ), '/tmp/dom-boxes.json');
    $collapseClassificationQuality = matrix_analyze_dom_box_report(array(
        'entrypoints' => array(
            array(
                'page_path' => '/classification.html',
                'viewport' => array('width' => 1440, 'height' => 900),
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'elements' => array(
                    array(
                        'node_id' => 'line:separator',
                        'tag' => 'svg',
                        'boundingClientRect' => array('left' => 0, 'right' => 240, 'top' => 0, 'bottom' => 1, 'width' => 240, 'height' => 1),
                        'visibility' => array('visible' => true),
                        'source' => array('node_type' => 'LINE', 'visual_dimensions' => array('width' => 240, 'height' => 1)),
                    ),
                    array(
                        'node_id' => 'vector:tail',
                        'tag' => 'svg',
                        'boundingClientRect' => array('left' => 0, 'right' => 0.1, 'top' => 10, 'bottom' => 90, 'width' => 0.1, 'height' => 80),
                        'visibility' => array('visible' => true),
                        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 0.1, 'height' => 80)),
                    ),
                    array(
                        'node_id' => 'vector:source-zero-dom-one',
                        'tag' => 'svg',
                        'boundingClientRect' => array('left' => 0, 'right' => 943, 'top' => 90, 'bottom' => 91, 'width' => 943, 'height' => 1),
                        'visibility' => array('visible' => true),
                        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 0)),
                    ),
                    array(
                        'node_id' => 'text:collapsed',
                        'tag' => 'p',
                        'boundingClientRect' => array('left' => 0, 'right' => 0, 'top' => 100, 'bottom' => 124, 'width' => 0, 'height' => 24),
                        'visibility' => array('visible' => false),
                        'source' => array('node_type' => 'TEXT', 'visual_dimensions' => array('width' => 180, 'height' => 24)),
                    ),
                    array(
                        'node_id' => 'frame:collapsed',
                        'tag' => 'section',
                        'boundingClientRect' => array('left' => 0, 'right' => 320, 'top' => 130, 'bottom' => 130, 'width' => 320, 'height' => 0),
                        'visibility' => array('visible' => false),
                        'source' => array('node_type' => 'FRAME', 'visual_dimensions' => array('width' => 320, 'height' => 120)),
                    ),
                    array(
                        'node_id' => 'vector:unexpected-collapse',
                        'tag' => 'svg',
                        'boundingClientRect' => array('left' => 0, 'right' => 1, 'top' => 140, 'bottom' => 200, 'width' => 1, 'height' => 60),
                        'visibility' => array('visible' => true),
                        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 8, 'height' => 60)),
                    ),
                ),
            ),
        ),
    ), '/tmp/dom-box-collapse-classification.json');
    $visibleVectorSourceZeroDomOne = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 0)),
    );
    $invisibleVectorSourceZeroDomOne = $visibleVectorSourceZeroDomOne;
    $invisibleVectorSourceZeroDomOne['visibility']['visible'] = false;
    $bothAxesCollapsedVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 1, 'height' => 1)),
    );
    $missingSourceAxisVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943)),
    );
    $nonNumericSourceAxisVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 'none')),
    );
    $sourceAxisNotCollapsedVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 2)),
    );
    $sourceAxisOneDomZeroVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 1)),
    );
    $sourceAxisFractionalDomZeroVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 0.75)),
    );
    $sourceAxisWithinToleranceVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => 0.75)),
    );
    $negativeSourceAxisVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => -0.25)),
    );
    $nanSourceAxisVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => NAN)),
    );
    $infiniteSourceAxisVector = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'VECTOR', 'visual_dimensions' => array('width' => 943, 'height' => INF)),
    );
    $ordinaryContentWithCollapsedAxis = array(
        'visibility' => array('visible' => true),
        'source' => array('node_type' => 'TEXT', 'visual_dimensions' => array('width' => 943, 'height' => 0)),
    );
    $invalidDomBoxQuality = matrix_analyze_dom_box_report(array(
        'schema' => 'homeboy/static-artifact-dom-boxes/v1',
        'entrypoints' => array(
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 1440, 'height' => 900, 'device_scale_factor' => 1),
                'dom_css_loaded' => false,
                'dom_capture_valid' => false,
                'stylesheet_status' => array('body_margin' => '8px', 'body_margin_reset' => false),
                'elements' => array(
                    array(
                        'node_id' => 'frame:root',
                        'node_name' => 'Root',
                        'selector' => 'main[data-figma-node-id="frame:root"]',
                        'tag' => 'main',
                        'boundingClientRect' => array('left' => 0, 'right' => 1488, 'top' => 0, 'bottom' => 100, 'width' => 1488, 'height' => 100),
                        'text_metrics' => array('scroll_width' => 1500, 'client_width' => 1440),
                    ),
                ),
            ),
        ),
    ), '/tmp/dom-boxes-invalid.json');
    $matrixQualitySummary = matrix_quality_matrix(array(
        array(
            'id' => 'ready-fixture',
            'status' => 'completed',
            'quality_status' => 'pass',
            'selected_frame_ids' => array('frame:home', 'frame:about'),
            'omitted_page_candidates' => array(),
            'quality_summary' => array(
                'fixed_width_declaration_count' => 4,
                'fixed_width_with_responsive_override_count' => 4,
                'vector_decode_coverage_ratio' => 1.0,
            ),
            'artifact_quality' => array('signals' => array()),
        ),
        array(
            'id' => 'risk-fixture',
            'status' => 'completed',
            'quality_status' => 'warn',
            'selected_frame_ids' => array('frame:home'),
            'omitted_page_candidates' => array(array('id' => 'frame:contact')),
            'quality_summary' => array(
                'fixed_width_declaration_count' => 4,
                'fixed_width_with_responsive_override_count' => 1,
                'fixed_width_without_responsive_override_count' => 3,
                'fallback_prone_input_island_count' => 2,
                'link_targets_unresolved' => 1,
                'vector_placeholders' => 1,
                'html_artifact' => array(
                    'breakpoint_override_leak_count' => 2,
                ),
            ),
            'artifact_quality' => array('signals' => array(
                array('code' => 'responsive_fixed_width_without_override'),
                array('code' => 'fallback_prone_html_islands'),
            )),
            'dom_box_quality' => $domBoxQuality,
        ),
    ));
    $invalidDomBoxMatrixSummary = matrix_quality_matrix(array(
        array(
            'id' => 'invalid-dom-capture-fixture',
            'status' => 'completed',
            'selected_frame_ids' => array('frame:home'),
            'dom_box_quality' => $invalidDomBoxQuality,
        ),
    ));
    $roleSeparatedDomBoxQuality = matrix_analyze_dom_box_report(array(
        'entrypoints' => array(
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 1200, 'height' => 900),
                'source_frame' => array('id' => 'frame:desktop', 'width' => 1200),
                'comparison_role' => 'source_layout',
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'elements' => array(),
            ),
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 640, 'height' => 900),
                'source_frame' => array('id' => 'frame:mobile', 'width' => 640),
                'comparison_role' => 'responsive_evidence',
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'elements' => array(
                    array('node_id' => 'responsive:overflow', 'boundingClientRect' => array('left' => 0, 'right' => 700, 'top' => 0, 'bottom' => 20, 'width' => 700, 'height' => 20)),
                ),
            ),
        ),
    ));
    $roleSeparatedMatrixSummary = matrix_quality_matrix(array(
        array(
            'id' => 'role-separated-dom-fixture',
            'status' => 'completed',
            'dom_box_quality' => $roleSeparatedDomBoxQuality,
        ),
    ));
    $unclassifiedOnlyMatrixSummary = matrix_quality_matrix(array(
        array(
            'id' => 'unclassified-dom-fixture',
            'status' => 'completed',
            'dom_box_quality' => $domBoxQuality,
        ),
    ));
    $mixedRoleDomBoxQuality = matrix_analyze_dom_box_report(array(
        'entrypoints' => array(
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 1200, 'height' => 900),
                'comparison_role' => 'source_layout',
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'elements' => array(),
            ),
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 640, 'height' => 900),
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'elements' => array(
                    array('node_id' => 'unclassified:overflow', 'boundingClientRect' => array('left' => 0, 'right' => 700, 'top' => 0, 'bottom' => 20, 'width' => 700, 'height' => 20)),
                ),
            ),
            array(
                'page_path' => '/index.html',
                'viewport' => array('width' => 640, 'height' => 900),
                'comparison_role' => 'responsive_evidence',
                'dom_css_loaded' => true,
                'dom_capture_valid' => true,
                'elements' => array(
                    array('node_id' => 'responsive:overflow', 'boundingClientRect' => array('left' => 0, 'right' => 700, 'top' => 0, 'bottom' => 20, 'width' => 700, 'height' => 20)),
                ),
            ),
        ),
    ));
    $mixedRoleMatrixSummary = matrix_quality_matrix(array(
        array(
            'id' => 'mixed-role-dom-fixture',
            'status' => 'completed',
            'dom_box_quality' => $mixedRoleDomBoxQuality,
        ),
    ));

    $matrixSelectionLockPath = $matrixFixtureDir . '/selection-lock.json';
    file_put_contents($matrixSelectionLockPath, json_encode(array(
        'schema'   => 'blocks-engine/figma-transformer/fixture-matrix/v1',
        'fixtures' => array(
            array(
                'id'                 => 'alias',
                'selected_frame_ids' => array('locked:home', 'locked:about'),
                'entry_frame_id'     => 'locked:home',
            ),
        ),
    ), JSON_THROW_ON_ERROR));

    $matrixDryRun = static function (array $args) use ($matrixFixtureDir): ?array {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
            . ' --dry-run --capture-dom-boxes --fixture-dir=' . escapeshellarg($matrixFixtureDir);
        foreach ( $args as $arg ) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = shell_exec($command);
        return is_string($output) ? json_decode($output, true) : null;
    };

    $matrixAliasSummary = $matrixDryRun(array(
        '--homeboy-bin=/opt/homeboy-alias',
        '--dom-box-command=node dom-box-alias',
    ));
    $matrixCanonicalSummary = $matrixDryRun(array(
        '--homeboy-command=/opt/homeboy-canonical',
        '--dom-box-provider-command=node dom-box-canonical',
    ));
    $matrixSelectionLockSummary = $matrixDryRun(array(
        '--selection-lock=' . $matrixSelectionLockPath,
    ));
    $matrixEvidenceSummary = $matrixDryRun(array(
        '--frame-ids=render:home',
        '--render-evidence=' . $matrixFixtureDir . '/{fixture}/render-evidence.json',
    ));
    $matrixScreenshotSummary = $matrixDryRun(array(
        '--frame-ids=render:home',
        '--source-screenshot=' . $matrixFixtureDir . '/screenshots/{fixture}/{slug}-source.png',
        '--generated-screenshot=' . $matrixFixtureDir . '/screenshots/{fixture}/{slug}-generated.png',
        '--diff-image=' . $matrixFixtureDir . '/screenshots/{fixture}/{slug}-diff.png',
    ));
    $missingHomeboyOutput = array();
    $missingHomeboyCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --capture-dom-boxes --fixture-dir=' . escapeshellarg($matrixFixtureDir)
        . ' --homeboy-command=' . escapeshellarg($matrixFixtureDir . '/missing-homeboy')
        . ' 2>&1';
    exec($missingHomeboyCommand, $missingHomeboyOutput, $missingHomeboyExitCode);
    $missingProviderOutput = array();
    $missingProviderCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --capture-dom-boxes --fixture-dir=' . escapeshellarg($matrixFixtureDir)
        . ' --homeboy-command=' . escapeshellarg(PHP_BINARY)
        . ' 2>&1';
    exec($missingProviderCommand, $missingProviderOutput, $missingProviderExitCode);
    $matrixHelpOutput = array();
    $matrixHelpCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --help'
        . ' 2>&1';
    exec($matrixHelpCommand, $matrixHelpOutput, $matrixHelpExitCode);
    $explicitFixtureCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --dry-run --fixture-dir=' . escapeshellarg($matrixFixtureDir)
        . ' --fixture=' . escapeshellarg($matrixFixtureDir . '/explicit.fig');
    $explicitFixtureOutput = shell_exec($explicitFixtureCommand);
    $explicitFixtureSummary = is_string($explicitFixtureOutput) ? json_decode($explicitFixtureOutput, true) : null;
    $missingExplicitFixtureOutput = array();
    $missingExplicitFixtureCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --dry-run --fixture=' . escapeshellarg($matrixFixtureDir . '/missing.fig')
        . ' 2>&1';
    exec($missingExplicitFixtureCommand, $missingExplicitFixtureOutput, $missingExplicitFixtureExitCode);

    $assert(! in_array('frame:title-card', $matrixSelection, true), 'fixture-matrix-selection-skips-dev-marked-title-card');
    $assert(array('frame:home-desktop', 'frame:single-desktop') === $matrixSelection, 'fixture-matrix-selection-falls-back-to-page-like-frames');
    $assert(array('frame:fisiostetic-home') === $wideRootSelection, 'fixture-matrix-selection-prefers-wide-root-page-over-section-frame');
    $assert('frame:component-front-page' === ($componentComposedFrontPageSelection[0] ?? null), 'fixture-matrix-selection-keeps-component-composed-front-page');
    $assert(! in_array('frame:comments-utility', $componentComposedFrontPageSelection, true), 'fixture-matrix-selection-skips-utility-template-frame');
    $assert(! in_array('frame:screenshot', $componentComposedFrontPageSelection, true), 'fixture-matrix-selection-skips-screenshot-utility-frame');
    $assert(array('frame:home-desktop', 'frame:single-desktop', 'frame:archive-desktop', 'frame:404-desktop', 'frame:page-desktop') === $canonicalTemplateSelection, 'fixture-matrix-selection-preserves-canonical-template-coverage-under-page-cap');
    $assert('frame:contact-desktop' === ($canonicalTemplateOmissions[0]['id'] ?? null), 'fixture-matrix-selection-reports-omitted-page-candidates');
    $assert('covered_by_selected_route_bucket' === ($coveredBucketOmissions[0]['reason'] ?? null), 'fixture-matrix-selection-explains-selected-bucket-omission');
    $assert('frame:home-desktop' === ($coveredBucketOmissions[0]['selected_bucket_frame_id'] ?? null), 'fixture-matrix-selection-reports-selected-bucket-frame');
    $assert('covered_by_selected_responsive_variant' === ($coveredResponsiveVariantOmissions[0]['reason'] ?? null), 'fixture-matrix-selection-explains-selected-responsive-variant-omission');
    $assert('frame:home-desktop' === ($coveredResponsiveVariantOmissions[0]['selected_responsive_sibling_frame_id'] ?? null), 'fixture-matrix-selection-reports-selected-responsive-sibling-frame');
    $assert('outside_page_cap' === ($pageCapOnlyOmissions[0]['reason'] ?? null), 'fixture-matrix-selection-explains-page-cap-omission');
    $assert(! array_key_exists('selected_bucket_frame_id', $pageCapOnlyOmissions[0] ?? array()), 'fixture-matrix-selection-omits-selected-bucket-frame-when-not-covered');
    $assert('medium' === ($matrixVisualReadiness['visual_risk_bucket'] ?? null), 'fixture-matrix-visual-readiness-buckets-risk');
    $assert(0.5 === ($matrixVisualReadiness['route_coverage_ratio'] ?? null), 'fixture-matrix-visual-readiness-route-coverage');
    $assert(4 === ($matrixVisualReadiness['risk_categories']['responsive_coverage']['count'] ?? null), 'fixture-matrix-visual-readiness-responsive-risk-count');
    $assert(7 === ($matrixVisualReadiness['risk_categories']['text_wrapping_leaks']['count'] ?? null), 'fixture-matrix-visual-readiness-text-risk-count');
    $assert(0.056 === ($cappedSelectionVisualReadiness['route_coverage_ratio'] ?? null), 'fixture-matrix-capped-selection-keeps-route-coverage-visible');
    $assert(0 === ($cappedSelectionVisualReadiness['risk_categories']['route_coverage']['count'] ?? null), 'fixture-matrix-page-cap-omissions-are-not-transform-risk');
    $assert(100 === ($cappedSelectionVisualReadiness['readiness_score'] ?? null), 'fixture-matrix-page-cap-does-not-reduce-readiness');
    $assert('low' === ($cappedSelectionVisualReadiness['visual_risk_bucket'] ?? null), 'fixture-matrix-page-cap-does-not-create-critical-risk');
    $assert(0.333 === ($coveredRouteVisualReadiness['route_coverage_ratio'] ?? null), 'fixture-matrix-covered-route-omissions-remain-in-coverage');
    $assert(0 === ($coveredRouteVisualReadiness['risk_categories']['route_coverage']['count'] ?? null), 'fixture-matrix-covered-route-omissions-are-not-transform-risk');
    $assert(100 === ($coveredRouteVisualReadiness['readiness_score'] ?? null), 'fixture-matrix-covered-route-omissions-do-not-reduce-readiness');
    $assert('blocks-engine/figma-transformer/dom-box-quality/v1' === ($domBoxQuality['schema'] ?? null), 'fixture-matrix-dom-box-quality-schema');
    $assert(2 === ($domBoxQuality['summary']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-dom-box-quality-horizontal-overflow');
    $assert(true === ($domBoxQuality['summary']['dom_css_loaded'] ?? null), 'fixture-matrix-dom-box-quality-css-loaded');
    $assert(true === ($domBoxQuality['summary']['dom_capture_valid'] ?? null), 'fixture-matrix-dom-box-quality-capture-valid');
    $assert(array_key_exists('dom_css_loaded', $domBoxQuality['pages'][0]['summary'] ?? array()), 'fixture-matrix-dom-box-page-summary-has-css-loaded');
    $assert(array_key_exists('dom_capture_valid', $domBoxQuality['pages'][0]['summary'] ?? array()), 'fixture-matrix-dom-box-page-summary-has-capture-valid');
    $assert(1 === ($domBoxQuality['summary']['dom_viewport_width_leak_count'] ?? null), 'fixture-matrix-dom-box-quality-viewport-width-leak');
    $assert(1 === ($domBoxQuality['summary']['dom_huge_vertical_spacing_count'] ?? null), 'fixture-matrix-dom-box-quality-huge-vertical-spacing');
    $assert(1 === ($domBoxQuality['summary']['dom_collapsed_box_count'] ?? null), 'fixture-matrix-dom-box-quality-collapsed-box');
    $assert(3 === ($collapseClassificationQuality['summary']['dom_collapsed_box_count'] ?? null), 'fixture-matrix-dom-box-classification-warns-for-genuine-collapse');
    $collapseFindingIds = array_map(
        static fn (array $finding): string => (string) ($finding['node']['id'] ?? ''),
        $collapseClassificationQuality['pages'][0]['findings'] ?? array()
    );
    $assert(! in_array('line:separator', $collapseFindingIds, true), 'fixture-matrix-dom-box-classification-allows-visible-one-pixel-line');
    $assert(! in_array('vector:tail', $collapseFindingIds, true), 'fixture-matrix-dom-box-classification-allows-source-faithful-subpixel-vector');
    $assert(! in_array('vector:source-zero-dom-one', $collapseFindingIds, true), 'fixture-matrix-dom-box-classification-allows-visible-vector-source-zero-dom-one-through-analyzer');
    $assert(! matrix_dom_box_is_unexpected_collapse($visibleVectorSourceZeroDomOne, 943, 1), 'fixture-matrix-dom-box-classification-allows-visible-vector-source-zero-dom-one');
    $assert(matrix_dom_box_is_unexpected_collapse($invisibleVectorSourceZeroDomOne, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-invisible-vector');
    $assert(matrix_dom_box_is_unexpected_collapse($bothAxesCollapsedVector, 1, 1), 'fixture-matrix-dom-box-classification-warns-for-both-axis-collapse');
    $assert(matrix_dom_box_is_unexpected_collapse($missingSourceAxisVector, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-missing-source-axis');
    $assert(matrix_dom_box_is_unexpected_collapse($nonNumericSourceAxisVector, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-non-numeric-source-axis');
    $assert(matrix_dom_box_is_unexpected_collapse($nanSourceAxisVector, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-nan-source-axis');
    $assert(matrix_dom_box_is_unexpected_collapse($infiniteSourceAxisVector, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-infinite-source-axis');
    $assert(matrix_dom_box_is_unexpected_collapse($negativeSourceAxisVector, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-negative-source-axis');
    $assert(matrix_dom_box_is_unexpected_collapse($sourceAxisNotCollapsedVector, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-non-collapsed-source-axis');
    $assert(matrix_dom_box_is_unexpected_collapse($sourceAxisOneDomZeroVector, 943, 0), 'fixture-matrix-dom-box-classification-warns-for-source-one-dom-zero');
    $assert(matrix_dom_box_is_unexpected_collapse($sourceAxisFractionalDomZeroVector, 943, 0), 'fixture-matrix-dom-box-classification-warns-for-source-fractional-dom-zero');
    $assert(! matrix_dom_box_is_unexpected_collapse($sourceAxisWithinToleranceVector, 943, 1), 'fixture-matrix-dom-box-classification-allows-source-within-agreement-tolerance');
    $assert(matrix_dom_box_is_unexpected_collapse($ordinaryContentWithCollapsedAxis, 943, 1), 'fixture-matrix-dom-box-classification-warns-for-ordinary-content');
    $assert(in_array('text:collapsed', $collapseFindingIds, true), 'fixture-matrix-dom-box-classification-warns-for-collapsed-text');
    $assert(in_array('frame:collapsed', $collapseFindingIds, true), 'fixture-matrix-dom-box-classification-warns-for-collapsed-container');
    $assert(in_array('vector:unexpected-collapse', $collapseFindingIds, true), 'fixture-matrix-dom-box-classification-warns-for-source-mismatched-vector');
    $unexpectedVectorFinding = array_values(array_filter(
        $collapseClassificationQuality['pages'][0]['findings'] ?? array(),
        static fn (array $finding): bool => 'vector:unexpected-collapse' === ($finding['node']['id'] ?? null)
    ))[0] ?? array();
    $assert('VECTOR' === ($unexpectedVectorFinding['node']['source_node_type'] ?? null), 'fixture-matrix-dom-box-finding-preserves-source-node-type');
    $assert(8 === ($unexpectedVectorFinding['node']['source_visual_dimensions']['width'] ?? null), 'fixture-matrix-dom-box-finding-preserves-source-visual-dimensions');
    $assert(1 === ($domBoxQuality['summary']['dom_offscreen_box_count'] ?? null), 'fixture-matrix-dom-box-quality-offscreen-box');
    $assert(2 === ($domBoxQuality['summary']['dom_missing_node_id_box_count'] ?? null), 'fixture-matrix-dom-box-quality-missing-node-id-boxes');
    $assert(false === ($invalidDomBoxQuality['summary']['dom_css_loaded'] ?? null), 'fixture-matrix-invalid-dom-box-css-not-loaded');
    $assert(false === ($invalidDomBoxQuality['summary']['dom_capture_valid'] ?? null), 'fixture-matrix-invalid-dom-box-capture-invalid');
    $assert(false === ($invalidDomBoxQuality['pages'][0]['summary']['dom_css_loaded'] ?? null), 'fixture-matrix-invalid-dom-box-page-css-not-loaded');
    $assert(false === ($invalidDomBoxQuality['pages'][0]['summary']['dom_capture_valid'] ?? null), 'fixture-matrix-invalid-dom-box-page-capture-invalid');
    $assert(1 === ($invalidDomBoxQuality['summary']['dom_capture_invalid_count'] ?? null), 'fixture-matrix-invalid-dom-box-invalid-count');
    $assert(0 === ($invalidDomBoxQuality['summary']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-invalid-dom-box-does-not-score-horizontal-overflow');
    $assert('dom_capture_invalid' === ($invalidDomBoxQuality['pages'][0]['findings'][0]['code'] ?? null), 'fixture-matrix-invalid-dom-box-finding');
    $assert('blocks-engine/figma-transformer/fixture-matrix-quality/v1' === ($matrixQualitySummary['schema'] ?? null), 'fixture-matrix-quality-schema');
    $assert(0.625 === ($matrixQualitySummary['effective_responsive_coverage_ratio'] ?? null), 'fixture-matrix-quality-responsive-coverage-ratio');
    $assert(0.75 === ($matrixQualitySummary['route_coverage_ratio'] ?? null), 'fixture-matrix-quality-route-coverage-ratio');
    $assert(2 === ($matrixQualitySummary['totals']['breakpoint_override_leak_count'] ?? null), 'fixture-matrix-quality-nested-html-artifact-total');
    $assert(2 === ($matrixQualitySummary['totals']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-quality-dom-horizontal-overflow-total');
    $assert(2 === ($matrixQualitySummary['totals']['dom_missing_node_id_box_count'] ?? null), 'fixture-matrix-quality-dom-missing-node-id-total');
    $assert(3 === ($matrixQualitySummary['risk_category_totals']['responsive_coverage'] ?? null), 'fixture-matrix-quality-risk-category-total');
    $assert(8 === ($matrixQualitySummary['risk_category_totals']['rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-rendered-dom-risk-category-total');
    $assert(1 === ($invalidDomBoxMatrixSummary['totals']['dom_capture_invalid_count'] ?? null), 'fixture-matrix-quality-invalid-dom-capture-total');
    $assert(false === ($invalidDomBoxMatrixSummary['per_fixture_readiness'][0]['dom_capture_valid'] ?? null), 'fixture-matrix-quality-invalid-dom-capture-readiness-flag');
    $assert(false === ($invalidDomBoxMatrixSummary['per_fixture_readiness'][0]['dom_css_loaded'] ?? null), 'fixture-matrix-quality-invalid-dom-css-loaded-flag');
    $assert(0 === ($invalidDomBoxMatrixSummary['risk_category_totals']['rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-invalid-dom-not-scored-as-rendered-risk');
    $assert(in_array('dom_capture_invalid', $invalidDomBoxMatrixSummary['per_fixture_readiness'][0]['risk_categories']['rendered_dom_boxes']['signals'] ?? array(), true), 'fixture-matrix-quality-invalid-dom-risk-signal');
    $assert('source_layout' === ($roleSeparatedDomBoxQuality['pages'][0]['comparison_role'] ?? null), 'fixture-matrix-dom-box-page-preserves-comparison-role');
    $assert('frame:desktop' === ($roleSeparatedDomBoxQuality['pages'][0]['source_frame']['id'] ?? null), 'fixture-matrix-dom-box-page-preserves-source-frame');
    $assert(0 === ($roleSeparatedDomBoxQuality['summary_by_comparison_role']['source_layout']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-dom-box-source-summary-keeps-source-overflow');
    $assert(1 === ($roleSeparatedDomBoxQuality['summary_by_comparison_role']['responsive_evidence']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-dom-box-responsive-summary-keeps-responsive-overflow');
    $assert(1 === ($roleSeparatedMatrixSummary['totals']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-quality-role-separated-keeps-whole-dom-total');
    $assert(0 === ($roleSeparatedMatrixSummary['totals_by_comparison_role']['source_layout']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-quality-role-separated-source-total');
    $assert(1 === ($roleSeparatedMatrixSummary['totals_by_comparison_role']['responsive_evidence']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-quality-role-separated-responsive-total');
    $assert(0 === ($roleSeparatedMatrixSummary['risk_category_totals']['rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-role-separated-source-readiness');
    $assert(2 === ($roleSeparatedMatrixSummary['risk_category_totals']['responsive_rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-role-separated-responsive-readiness');
    $assert(8 === ($unclassifiedOnlyMatrixSummary['risk_category_totals']['rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-unclassified-source-readiness');
    $assert(0 === ($unclassifiedOnlyMatrixSummary['risk_category_totals']['responsive_rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-unclassified-not-responsive-readiness');
    $assert(92 === ($unclassifiedOnlyMatrixSummary['per_fixture_readiness'][0]['readiness_score'] ?? null), 'fixture-matrix-quality-unclassified-readiness-score-no-double-counting');
    $assert(2 === ($mixedRoleMatrixSummary['totals']['dom_horizontal_overflow_count'] ?? null), 'fixture-matrix-quality-mixed-role-keeps-whole-dom-total');
    $assert(2 === ($mixedRoleMatrixSummary['risk_category_totals']['rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-mixed-role-merges-unclassified-into-source');
    $assert(2 === ($mixedRoleMatrixSummary['risk_category_totals']['responsive_rendered_dom_boxes'] ?? null), 'fixture-matrix-quality-mixed-role-keeps-responsive-separate');
    $assert(96 === ($mixedRoleMatrixSummary['per_fixture_readiness'][0]['readiness_score'] ?? null), 'fixture-matrix-quality-mixed-role-readiness-score-no-double-counting');
    $assert(2 === count($matrixQualitySummary['per_fixture_readiness'] ?? array()), 'fixture-matrix-quality-per-fixture-readiness');
    $assert(is_array($matrixAliasSummary), 'fixture-matrix-alias-json-summary');
    $assert('/opt/homeboy-alias' === ($matrixAliasSummary['homeboy_command'] ?? null), 'fixture-matrix-homeboy-bin-alias');
    $assert(true === ($matrixAliasSummary['dom_box_provider_command_configured'] ?? null), 'fixture-matrix-dom-box-command-alias-configured');
    $matrixAliasCaptureCommand = (string) ($matrixAliasSummary['fixtures'][0]['dom_box_capture']['command'] ?? '');
    $assert(str_contains($matrixAliasCaptureCommand, escapeshellarg('/opt/homeboy-alias')), 'fixture-matrix-alias-capture-uses-homeboy-bin');
    $assert(str_contains($matrixAliasCaptureCommand, 'HOMEBOY_DOM_BOX_CAPTURE_COMMAND=' . escapeshellarg('node dom-box-alias')), 'fixture-matrix-alias-capture-uses-dom-box-command');
    $assert(str_contains($matrixAliasCaptureCommand, 'HOMEBOY_DOM_BOX_NODE_ID_ATTR=' . escapeshellarg('data-figma-node-id')), 'fixture-matrix-supplies-figma-node-id-contract');
    $assert(str_contains($matrixAliasCaptureCommand, 'HOMEBOY_DOM_BOX_NODE_NAME_ATTR=' . escapeshellarg('data-figma-node-name,data-figma-name')), 'fixture-matrix-supplies-figma-node-name-contract');

    $assert(is_array($matrixCanonicalSummary), 'fixture-matrix-canonical-json-summary');
    $assert('/opt/homeboy-canonical' === ($matrixCanonicalSummary['homeboy_command'] ?? null), 'fixture-matrix-homeboy-command-canonical');
    $assert(true === ($matrixCanonicalSummary['dom_box_provider_command_configured'] ?? null), 'fixture-matrix-dom-box-provider-command-canonical-configured');
    $matrixCanonicalCaptureCommand = (string) ($matrixCanonicalSummary['fixtures'][0]['dom_box_capture']['command'] ?? '');
    $assert(str_contains($matrixCanonicalCaptureCommand, escapeshellarg('/opt/homeboy-canonical')), 'fixture-matrix-canonical-capture-uses-homeboy-command');
    $assert(str_contains($matrixCanonicalCaptureCommand, 'HOMEBOY_DOM_BOX_CAPTURE_COMMAND=' . escapeshellarg('node dom-box-canonical')), 'fixture-matrix-canonical-capture-uses-dom-box-provider-command');

    $assert(is_array($matrixSelectionLockSummary), 'fixture-matrix-selection-lock-json-summary');
    $assert('locked_frame_ids' === ($matrixSelectionLockSummary['selection']['mode'] ?? null), 'fixture-matrix-selection-lock-summary-mode');
    $assert(1 === ($matrixSelectionLockSummary['selection']['fixture_count'] ?? null), 'fixture-matrix-selection-lock-fixture-count');
    $assert('selection_lock' === ($matrixSelectionLockSummary['fixtures'][0]['selection'] ?? null), 'fixture-matrix-selection-lock-fixture-source');
    $matrixSelectionLockCommand = (string) ($matrixSelectionLockSummary['fixtures'][0]['command'] ?? '');
    $assert(str_contains($matrixSelectionLockCommand, "--frame-ids='locked:home,locked:about'"), 'fixture-matrix-selection-lock-frame-ids');
    $assert(str_contains($matrixSelectionLockCommand, "--entry-frame-id='locked:home'"), 'fixture-matrix-selection-lock-entry-frame');
    $assert(is_array($matrixEvidenceSummary), 'fixture-matrix-render-evidence-json-summary');
    $assert($matrixFixtureDir . '/{fixture}/render-evidence.json' === ($matrixEvidenceSummary['evidence']['templates']['render_evidence_path'] ?? null), 'fixture-matrix-render-evidence-template-summary');
    $matrixEvidenceCommand = (string) ($matrixEvidenceSummary['fixtures'][0]['command'] ?? '');
    $assert(str_contains($matrixEvidenceCommand, '--parity-render-evidence-path=' . escapeshellarg($matrixFixtureDir . '/alias/render-evidence.json')), 'fixture-matrix-render-evidence-transform-argument');
    $assert(is_array($matrixScreenshotSummary), 'fixture-matrix-screenshot-json-summary');
    $assert($matrixFixtureDir . '/screenshots/{fixture}/{slug}-source.png' === ($matrixScreenshotSummary['evidence']['templates']['source_screenshot_path'] ?? null), 'fixture-matrix-source-screenshot-template-summary');
    $matrixScreenshotPaths = $matrixScreenshotSummary['fixtures'][0]['evidence']['pages'][0]['paths'] ?? array();
    $assert(false === ($matrixScreenshotPaths['source_screenshot_path']['exists'] ?? null), 'fixture-matrix-source-screenshot-missing-recorded');
    $matrixScreenshotCommand = (string) ($matrixScreenshotSummary['fixtures'][0]['command'] ?? '');
    $assert(str_contains($matrixScreenshotCommand, '--parity-source-screenshot-path=' . escapeshellarg($matrixFixtureDir . '/screenshots/alias/alias-source.png')), 'fixture-matrix-source-screenshot-transform-argument');
    $assert(str_contains($matrixScreenshotCommand, '--parity-generated-screenshot-path=' . escapeshellarg($matrixFixtureDir . '/screenshots/alias/alias-generated.png')), 'fixture-matrix-generated-screenshot-transform-argument');
    $assert(str_contains($matrixScreenshotCommand, '--parity-diff-image-path=' . escapeshellarg($matrixFixtureDir . '/screenshots/alias/alias-diff.png')), 'fixture-matrix-diff-image-transform-argument');
    $assert(0 !== $missingHomeboyExitCode, 'fixture-matrix-capture-preflight-missing-homeboy-fails');
    $missingHomeboyMessage = implode("\n", $missingHomeboyOutput);
    $assert(str_contains($missingHomeboyMessage, 'DOM box capture requires a runnable Homeboy command'), 'fixture-matrix-capture-preflight-missing-homeboy-message');
    $assert(str_contains($missingHomeboyMessage, 'Set --homeboy-command, --homeboy-bin, or HOMEBOY_COMMAND'), 'fixture-matrix-capture-preflight-homeboy-remediation');
    $assert(0 !== $missingProviderExitCode, 'fixture-matrix-capture-preflight-missing-provider-fails');
    $missingProviderMessage = implode("\n", $missingProviderOutput);
    $assert(str_contains($missingProviderMessage, 'DOM box capture requires a provider command'), 'fixture-matrix-capture-preflight-missing-provider-message');
    $assert(str_contains($missingProviderMessage, "--dom-box-provider-command='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs'"), 'fixture-matrix-capture-preflight-provider-remediation');
    $assert(str_contains($missingProviderMessage, 'npm ci --prefix php-transformer/tools/visual-parity'), 'fixture-matrix-capture-preflight-provider-install-command');
    $assert(0 === $matrixHelpExitCode, 'fixture-matrix-help-exits-zero');
    $matrixHelpMessage = implode("\n", $matrixHelpOutput);
    $assert(str_contains($matrixHelpMessage, 'Usage:'), 'fixture-matrix-help-usage');
    $assert(str_contains($matrixHelpMessage, '--fixture=/path/to/file.fig'), 'fixture-matrix-help-explicit-fixture');
    $assert(is_array($explicitFixtureSummary), 'fixture-matrix-explicit-fixture-json-summary');
    $assert(1 === count($explicitFixtureSummary['fixtures'] ?? array()), 'fixture-matrix-explicit-fixture-disables-dir-discovery');
    $assert($matrixFixtureDir . '/explicit.fig' === ($explicitFixtureSummary['fixtures'][0]['path'] ?? null), 'fixture-matrix-explicit-fixture-path');
    $assert(0 !== $missingExplicitFixtureExitCode, 'fixture-matrix-missing-explicit-fixture-fails');
    $missingExplicitFixtureMessage = implode("\n", $missingExplicitFixtureOutput);
    $assert(str_contains($missingExplicitFixtureMessage, 'Explicit fixture is not readable'), 'fixture-matrix-missing-explicit-fixture-message');
}

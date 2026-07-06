<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 * @param callable(array, string): string $fileContent
 */
function blocks_engine_figma_transformer_run_image_paint_contract(callable $assert, array $result, string $css, callable $fileContent): void
{
    $assert(2 === ($result['metrics']['asset_count'] ?? null), 'asset-count');
    $html = $fileContent($result, 'index.html');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $css, '.figma-node-1-4-hero-image-rectangle', array('width:320px', 'height:180px', 'position:absolute', 'left:10px', 'top:20px', 'background:#ff0000', 'background-image:url("assets/hero-image.svg")'), 'css-rectangle-asset-style');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $css, '.figma-node-1-5-nested-image-paint', array('background-image:url("assets/fixture-photo.jpg")'), 'css-nested-image-hash-asset-style');
    $assert(str_contains($html, '<img class="figma-node-1-4-hero-image-rectangle figma-image-asset"') && str_contains($html, 'src="assets/hero-image.svg"') && str_contains($html, 'data-figma-image-fill="true"') && str_contains($html, 'data-figma-image-rendering="semantic-img"'), 'asset-backed-rectangle-emits-img-element');
    $assert(str_contains($html, '<img class="figma-node-1-5-nested-image-paint figma-image-asset"') && str_contains($html, 'src="assets/fixture-photo.jpg"') && str_contains($html, 'data-figma-image-scale-mode="FILL"') && str_contains($html, 'data-figma-image-background-size=') && str_contains($html, 'data-figma-image-object-fit="cover"'), 'image-paint-emits-img-with-crop-metadata');
    $assert('fixture image bytes' === $fileContent($result, 'assets/fixture-photo.jpg'), 'asset-content-preserved');

    $imageUnderlayGuardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Underlay Guard Fixture',
        'assets' => array(
            'guard-image' => array(
                'name'      => 'Guard Image',
                'mime_type' => 'image/svg+xml',
                'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
            ),
        ),
        'nodes'  => array(
            array(
                'id'         => 'imageguard:parent',
                'type'       => 'FRAME',
                'name'       => 'Image Parent',
                'width'      => 1000,
                'height'     => 600,
                'layoutMode' => 'HORIZONTAL',
                'children'   => array(
                    array(
                        'id'       => 'imageguard:photo',
                        'type'     => 'RECTANGLE',
                        'name'     => 'Large Photo',
                        'width'    => 900,
                        'height'   => 520,
                        'asset_id' => 'guard-image',
                    ),
                    array('id' => 'imageguard:title', 'type' => 'TEXT', 'name' => 'Hero title', 'text' => 'Photo should stay in flow'),
                ),
            ),
        ),
    ));
    $imageUnderlayGuardCss = $fileContent($imageUnderlayGuardResult, 'style.css');
    $imageUnderlayGuardUnderlays = $imageUnderlayGuardResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $imageUnderlayGuardCss, '.figma-node-imageguard-photo-large-photo', array('width:900px', 'height:520px', 'background-image:url("assets/guard-image.svg")', 'background-size:cover', 'background-position:center', 'flex-shrink:0'), 'image-backed-child-remains-flex-child');
    $assert(str_contains($fileContent($imageUnderlayGuardResult, 'index.html'), '<img class="figma-node-imageguard-photo-large-photo figma-image-asset"') && str_contains($fileContent($imageUnderlayGuardResult, 'index.html'), 'data-figma-image-object-fit="cover"'), 'image-backed-child-emits-semantic-img-with-fit-metadata');
    $assert(0 === ($imageUnderlayGuardUnderlays['count'] ?? null), 'image-backed-child-not-decorative-underlay-diagnostic');

    $imageBackedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Backed Vector Fixture',
        'assets' => array(
            'vector-photo' => array('mime_type' => 'image/png', 'content' => 'vector photo'),
        ),
        'nodes'  => array(
            array(
                'id'           => 'imagevector:photo',
                'type'         => 'VECTOR',
                'name'         => 'Photo Vector Layer',
                'width'        => 120,
                'height'       => 80,
                'figma_paints' => array(
                    'fills' => array(array('type' => 'IMAGE', 'ref' => 'vector-photo')),
                ),
            ),
        ),
    ));
    $imageBackedVectorHtml = $fileContent($imageBackedVectorResult, 'index.html');
    $imageBackedVectorCss = $fileContent($imageBackedVectorResult, 'style.css');
    $imageBackedVectorDiagnostics = $imageBackedVectorResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $imageBackedVectorCss, '.figma-node-imagevector-photo-photo-vector-layer', array('width:120px', 'height:80px', 'background-image:url("assets/vector-photo.png")', 'background-size:cover', 'background-position:center'), 'image-backed-vector-emits-image-background');
    $assert(str_contains($imageBackedVectorHtml, 'data-figma-node-id="imagevector:photo"') && ! str_contains($imageBackedVectorHtml, 'data-figma-vector="true"'), 'image-backed-vector-does-not-emit-vector-svg');
    $assert(0 === ($imageBackedVectorDiagnostics['vectors']['placeholders'] ?? null), 'image-backed-vector-not-counted-as-placeholder');
    $assert(1 === ($imageBackedVectorDiagnostics['images']['paint_refs'] ?? null), 'image-backed-vector-image-paint-evidence-counted');

    $queryCardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Query Card Semantics Fixture',
        'assets' => array(
            'card-photo' => array('mime_type' => 'image/jpeg', 'content' => 'card photo'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'query:root',
                'type'     => 'FRAME',
                'name'     => 'Query Loop',
                'width'    => 640,
                'height'   => 360,
                'children' => array(
                    array(
                        'id'       => 'query:post',
                        'type'     => 'FRAME',
                        'name'     => 'post',
                        'width'    => 320,
                        'height'   => 240,
                        'children' => array(
                            array('id' => 'query:image', 'type' => 'RECTANGLE', 'name' => 'Featured image', 'width' => 320, 'height' => 160, 'asset_id' => 'card-photo'),
                            array('id' => 'query:category', 'type' => 'TEXT', 'name' => 'Category', 'text' => 'News'),
                            array('id' => 'query:title', 'type' => 'TEXT', 'name' => 'Post title', 'text' => 'A real article card'),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $queryCardHtml = $fileContent($queryCardResult, 'index.html');
    $assert(str_contains($queryCardHtml, '<section class="figma-node-query-root-query-loop"') && str_contains($queryCardHtml, 'data-figma-collection="posts"') && str_contains($queryCardHtml, 'data-figma-template-hint="archive"'), 'query-container-emits-archive-hints');
    $assert(str_contains($queryCardHtml, '<article class="figma-node-query-post-post"') && str_contains($queryCardHtml, 'data-figma-content-kind="post-card"') && str_contains($queryCardHtml, 'data-figma-query-item="true"'), 'image-title-card-emits-article-hints');

    $imageMaskOverlayResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Mask Overlay Fixture',
        'assets' => array(
            'social-mask' => array('mime_type' => 'image/svg+xml', 'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'imagemask:root',
                'type'     => 'FRAME',
                'name'     => 'Social Icon Composition',
                'width'    => 24,
                'height'   => 24,
                'children' => array(
                    array('id' => 'imagemask:asset', 'type' => 'RECTANGLE', 'name' => 'Instagram asset layer', 'width' => 24, 'height' => 24, 'asset_id' => 'social-mask'),
                    array('id' => 'imagemask:overlay', 'type' => 'RECTANGLE', 'name' => 'Instagram overlay', 'width' => 24, 'height' => 24, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.2, 'b' => 0.4, 'a' => 1)))),
                ),
            ),
        ),
    ));
    $imageMaskOverlayHtml = $fileContent($imageMaskOverlayResult, 'index.html');
    $imageMaskOverlayCss = $fileContent($imageMaskOverlayResult, 'style.css');
    $imageMaskOverlayDiagnostics = $imageMaskOverlayResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(! str_contains($imageMaskOverlayHtml, 'data-figma-node-id="imagemask:asset"'), 'image-mask-social-alpha-source-not-emitted-as-visible-underlay');
    blocks_engine_figma_transformer_contract_assert_css_rule_absent($assert, $imageMaskOverlayCss, '.figma-node-imagemask-asset-instagram-asset-layer', 'image-mask-social-alpha-source-has-no-visible-css-underlay');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $imageMaskOverlayCss, '.figma-node-imagemask-overlay-instagram-overlay', array('width:24px', 'height:24px', '-webkit-mask-image:url("assets/social-mask.svg")', 'mask-image:url("assets/social-mask.svg")'), 'image-mask-social-overlay-emits-css-mask');
    $assert(! str_contains($imageMaskOverlayHtml, 'data-figma-node-id="imagemask:asset" data-figma-node-name="Instagram asset layer"><svg') && ! str_contains($imageMaskOverlayHtml, 'data-figma-node-id="imagemask:overlay" data-figma-node-name="Instagram overlay"><svg'), 'image-mask-social-composition-does-not-emit-duplicate-svg-children');
    $assert(! str_contains($imageMaskOverlayHtml, 'data-figma-unsupported-vector="true"'), 'image-mask-social-composition-has-no-placeholder-svg');
    $assert(0 === ($imageMaskOverlayDiagnostics['vectors']['placeholders'] ?? null), 'image-mask-social-composition-not-counted-as-vector-placeholder');
    $assert(1 === ($imageMaskOverlayDiagnostics['decision_traces']['reason_counts']['image_mask_alpha_source_suppressed'] ?? null), 'image-mask-social-alpha-source-suppression-traced');

    $vectorStateDuplicateResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Colocated Vector State Duplicate Fixture',
        'nodes' => array(
            array(
                'id'       => 'state-dup:root',
                'type'     => 'FRAME',
                'name'     => 'Carousel Controls',
                'width'    => 64,
                'height'   => 64,
                'children' => array(
                    array(
                        'id'       => 'state-dup:right-arrow-2',
                        'type'     => 'VECTOR',
                        'name'     => 'right-arrow 2',
                        'x'        => 20,
                        'y'        => 20,
                        'width'    => 24,
                        'height'   => 24,
                        'pathData' => 'M4 12H20M14 6L20 12L14 18',
                        'fills'    => array(array('type' => 'SOLID', 'color' => array('r' => 0.0, 'g' => 0.0, 'b' => 0.0, 'a' => 1))),
                    ),
                    array(
                        'id'       => 'state-dup:right-arrow-3',
                        'type'     => 'VECTOR',
                        'name'     => 'right-arrow 3',
                        'x'        => 20,
                        'y'        => 20,
                        'width'    => 24,
                        'height'   => 24,
                        'pathData' => 'M4 12H20M14 6L20 12L14 18',
                        'fills'    => array(array('type' => 'SOLID', 'color' => array('r' => 0.9, 'g' => 0.1, 'b' => 0.1, 'a' => 1))),
                    ),
                ),
            ),
        ),
    ));
    $vectorStateDuplicateHtml = $fileContent($vectorStateDuplicateResult, 'index.html');
    $vectorStateDuplicateCss = $fileContent($vectorStateDuplicateResult, 'style.css');
    $vectorStateDuplicateDiagnostics = $vectorStateDuplicateResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(str_contains($vectorStateDuplicateHtml, 'data-figma-node-id="state-dup:right-arrow-2"'), 'same-path-vector-state-default-emitted');
    $assert(! str_contains($vectorStateDuplicateHtml, 'data-figma-node-id="state-dup:right-arrow-3"'), 'same-path-vector-state-duplicate-not-emitted');
    $assert(! str_contains($vectorStateDuplicateCss, '.figma-node-state-dup-right-arrow-3-right-arrow-3{'), 'same-path-vector-state-duplicate-has-no-visible-css');
    $assert(1 === ($vectorStateDuplicateDiagnostics['decision_traces']['reason_counts']['same_path_vector_state_duplicate_suppressed'] ?? null), 'same-path-vector-state-duplicate-suppression-traced');

    $wrappedVectorStateDuplicateResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Wrapped Vector State Duplicate Fixture',
        'nodes' => array(
            array(
                'id'       => 'wrapped-state:root',
                'type'     => 'FRAME',
                'name'     => 'Carousel Controls',
                'width'    => 64,
                'height'   => 64,
                'children' => array(
                    array(
                        'id'       => 'wrapped-state:right-arrow-2',
                        'type'     => 'GROUP',
                        'name'     => 'right-arrow 2',
                        'x'        => 20,
                        'y'        => 20,
                        'width'    => 24,
                        'height'   => 24,
                        'children' => array(array(
                            'id'       => 'wrapped-state:right-arrow-2:vector',
                            'type'     => 'VECTOR',
                            'name'     => 'Vector',
                            'width'    => 24,
                            'height'   => 24,
                            'pathData' => 'M4 12H20M14 6L20 12L14 18',
                            'fills'    => array(array('type' => 'SOLID', 'color' => array('r' => 0.0, 'g' => 0.0, 'b' => 0.0, 'a' => 1))),
                        )),
                    ),
                    array(
                        'id'       => 'wrapped-state:right-arrow-3',
                        'type'     => 'GROUP',
                        'name'     => 'right-arrow 3',
                        'x'        => 20,
                        'y'        => 20,
                        'width'    => 24,
                        'height'   => 24,
                        'children' => array(array(
                            'id'       => 'wrapped-state:right-arrow-3:vector',
                            'type'     => 'VECTOR',
                            'name'     => 'Vector',
                            'width'    => 24,
                            'height'   => 24,
                            'pathData' => 'M4 12H20M14 6L20 12L14 18',
                            'fills'    => array(array('type' => 'SOLID', 'color' => array('r' => 0.9, 'g' => 0.1, 'b' => 0.1, 'a' => 1))),
                        )),
                    ),
                ),
            ),
        ),
    ));
    $wrappedVectorStateDuplicateHtml = $fileContent($wrappedVectorStateDuplicateResult, 'index.html');
    $assert(str_contains($wrappedVectorStateDuplicateHtml, 'data-figma-node-id="wrapped-state:right-arrow-2"'), 'wrapped-same-path-vector-state-default-emitted');
    $assert(! str_contains($wrappedVectorStateDuplicateHtml, 'data-figma-node-id="wrapped-state:right-arrow-3"'), 'wrapped-same-path-vector-state-duplicate-not-emitted');

    $nestedOffsetVectorStateDuplicateResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Offset Vector State Duplicate Fixture',
        'nodes' => array(
            array(
                'id'       => 'nested-offset-state:root',
                'type'     => 'FRAME',
                'name'     => 'Carousel Controls',
                'width'    => 1440,
                'height'   => 720,
                'children' => array(
                    array(
                        'id'       => 'nested-offset-state:right-arrow-2',
                        'type'     => 'FRAME',
                        'name'     => 'right-arrow 2',
                        'x'        => 1354,
                        'y'        => 424,
                        'width'    => 33,
                        'height'   => 33,
                        'children' => array(array(
                            'id'       => 'nested-offset-state:right-arrow-2:group',
                            'type'     => 'GROUP',
                            'name'     => 'Group',
                            'x'        => 0,
                            'y'        => 2.773,
                            'width'    => 33,
                            'height'   => 27.454,
                            'children' => array(array(
                                'id'       => 'nested-offset-state:right-arrow-2:inner-group',
                                'type'     => 'GROUP',
                                'name'     => 'Group',
                                'width'    => 33,
                                'height'   => 27.454,
                                'children' => array(array(
                                    'id'       => 'nested-offset-state:right-arrow-2:vector',
                                    'type'     => 'VECTOR',
                                    'name'     => 'Vector',
                                    'width'    => 33,
                                    'height'   => 27.454,
                                    'pathData' => 'M32.473 12.445L20.555 0.527 18.001 0.527 16.919 1.609 23.871 11.146 1.783 11.146 0 12.922 0 14.452 1.783 16.306 23.95 16.306 16.919 23.313 18.001 26.928 20.555 26.926 32.473 15.008Z',
                                    'fills'    => array(array('type' => 'SOLID', 'color' => array('r' => 0.514, 'g' => 0.847, 'b' => 0.921, 'a' => 1))),
                                )),
                            )),
                        )),
                    ),
                    array(
                        'id'       => 'nested-offset-state:right-arrow-3',
                        'type'     => 'FRAME',
                        'name'     => 'right-arrow 3',
                        'x'        => 1354,
                        'y'        => 423,
                        'width'    => 33,
                        'height'   => 33,
                        'children' => array(array(
                            'id'       => 'nested-offset-state:right-arrow-3:group',
                            'type'     => 'GROUP',
                            'name'     => 'Group',
                            'x'        => 0,
                            'y'        => 2.773,
                            'width'    => 33,
                            'height'   => 27.454,
                            'children' => array(array(
                                'id'       => 'nested-offset-state:right-arrow-3:inner-group',
                                'type'     => 'GROUP',
                                'name'     => 'Group',
                                'width'    => 33,
                                'height'   => 27.454,
                                'children' => array(array(
                                    'id'       => 'nested-offset-state:right-arrow-3:vector',
                                    'type'     => 'VECTOR',
                                    'name'     => 'Vector',
                                    'width'    => 33,
                                    'height'   => 27.454,
                                    'pathData' => 'M32.473 12.445L20.555 0.527 18.001 0.527 16.919 1.609 23.871 11.146 1.783 11.146 0 12.922 0 14.452 1.783 16.306 23.95 16.306 16.919 23.313 18.001 26.928 20.555 26.926 32.473 15.008Z',
                                    'fills'    => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                                )),
                            )),
                        )),
                    ),
                ),
            ),
        ),
    ));
    $nestedOffsetVectorStateDuplicateHtml = $fileContent($nestedOffsetVectorStateDuplicateResult, 'index.html');
    $nestedOffsetVectorStateDuplicateCss = $fileContent($nestedOffsetVectorStateDuplicateResult, 'style.css');
    $nestedOffsetVectorStateDuplicateDiagnostics = $nestedOffsetVectorStateDuplicateResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(str_contains($nestedOffsetVectorStateDuplicateHtml, 'data-figma-node-id="nested-offset-state:right-arrow-2"'), 'nested-offset-same-path-vector-state-default-emitted');
    $assert(! str_contains($nestedOffsetVectorStateDuplicateHtml, 'data-figma-node-id="nested-offset-state:right-arrow-3"'), 'nested-offset-same-path-vector-state-duplicate-not-emitted');
    $assert(! str_contains($nestedOffsetVectorStateDuplicateCss, '.figma-node-nested-offset-state-right-arrow-3-right-arrow-3{'), 'nested-offset-same-path-vector-state-duplicate-has-no-visible-css');
    $assert(1 === ($nestedOffsetVectorStateDuplicateDiagnostics['decision_traces']['reason_counts']['same_path_vector_state_duplicate_suppressed'] ?? null), 'nested-offset-same-path-vector-state-duplicate-suppression-traced');

    $generatedPathVectorStateDuplicateResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Generated Path Vector State Duplicate Fixture',
        'nodes' => array(
            array(
                'id'       => 'generated-state:root',
                'type'     => 'FRAME',
                'name'     => 'Carousel Controls',
                'width'    => 64,
                'height'   => 64,
                'children' => array(
                    array(
                        'id'                 => 'generated-state:right-arrow-2',
                        'type'               => 'VECTOR',
                        'name'               => 'right-arrow 2',
                        'x'                  => 20,
                        'y'                  => 20,
                        'width'              => 24,
                        'height'             => 24,
                        'figma_vector_paths' => array(array('data' => 'M4 12H20M14 6L20 12L14 18')),
                        'fills'              => array(array('type' => 'SOLID', 'color' => array('r' => 0.0, 'g' => 0.0, 'b' => 0.0, 'a' => 1))),
                    ),
                    array(
                        'id'                 => 'generated-state:right-arrow-3',
                        'type'               => 'VECTOR',
                        'name'               => 'right-arrow 3',
                        'x'                  => 20,
                        'y'                  => 20,
                        'width'              => 24,
                        'height'             => 24,
                        'figma_vector_paths' => array(array('data' => 'M4 12H20M14 6L20 12L14 18')),
                        'fills'              => array(array('type' => 'SOLID', 'color' => array('r' => 0.9, 'g' => 0.1, 'b' => 0.1, 'a' => 1))),
                    ),
                ),
            ),
        ),
    ));
    $generatedPathVectorStateDuplicateHtml = $fileContent($generatedPathVectorStateDuplicateResult, 'index.html');
    $generatedPathVectorStateDuplicateDiagnostics = $generatedPathVectorStateDuplicateResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(str_contains($generatedPathVectorStateDuplicateHtml, 'data-figma-node-id="generated-state:right-arrow-2"'), 'same-generated-path-vector-state-default-emitted');
    $assert(! str_contains($generatedPathVectorStateDuplicateHtml, 'data-figma-node-id="generated-state:right-arrow-3"'), 'same-generated-path-vector-state-duplicate-not-emitted');
    $assert(1 === ($generatedPathVectorStateDuplicateDiagnostics['decision_traces']['reason_counts']['same_path_vector_state_duplicate_suppressed'] ?? null), 'same-generated-path-vector-state-duplicate-suppression-traced');

    $largeImageTintResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Large Image Tint Overlay Fixture',
        'assets' => array(
            'large-photo' => array('mime_type' => 'image/png', 'content' => 'large photo'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'large-tint:root',
                'type'     => 'FRAME',
                'name'     => 'Hero artwork',
                'width'    => 480,
                'height'   => 320,
                'children' => array(
                    array('id' => 'large-tint:photo', 'type' => 'RECTANGLE', 'name' => 'Hero photo', 'width' => 480, 'height' => 320, 'asset_id' => 'large-photo'),
                    array('id' => 'large-tint:overlay', 'type' => 'RECTANGLE', 'name' => 'Hero tint overlay', 'width' => 480, 'height' => 320, 'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.35)))),
                ),
            ),
        ),
    ));
    $largeImageTintHtml = $fileContent($largeImageTintResult, 'index.html');
    $largeImageTintCss = $fileContent($largeImageTintResult, 'style.css');
    $assert(str_contains($largeImageTintHtml, 'data-figma-node-id="large-tint:photo"'), 'large-image-tint-photo-underlay-still-emitted');
    $assert(str_contains($largeImageTintCss, '.figma-node-large-tint-photo-hero-photo{') && str_contains($largeImageTintCss, 'background-image:url("assets/large-photo.png")'), 'large-image-tint-photo-underlay-keeps-background');

    $unusedAssetResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Unused Asset Fixture',
        'assets' => array(
            'used-image' => array('mime_type' => 'image/png', 'content' => 'used image'),
            'unused-image' => array('mime_type' => 'image/png', 'content' => 'unused image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'asset:used',
                'type'       => 'RECTANGLE',
                'name'       => 'Used image',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'used-image')),
            ),
        ),
    ));
    $unusedAssetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $unusedAssetResult['assets'] ?? array());
    $assert(1 === ($unusedAssetResult['metrics']['asset_count'] ?? null), 'unused-asset-filtered-count');
    $assert(in_array('assets/used-image.png', $unusedAssetPaths, true), 'unused-asset-keeps-referenced');
    $assert(! in_array('assets/unused-image.png', $unusedAssetPaths, true), 'unused-asset-omits-unreferenced');

    $backgroundPaintsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Background Paints Fixture',
        'nodes' => array(
            array(
                'id'               => 'background:paints',
                'type'             => 'FRAME',
                'name'             => 'Background Paints',
                'width'            => 10,
                'height'           => 10,
                'backgroundPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 1, 'b' => 0, 'a' => 1))),
            ),
        ),
    ));
    $backgroundPaintsCss = $fileContent($backgroundPaintsResult, 'style.css');
    $assert(str_contains($backgroundPaintsCss, '.figma-node-background-paints-background-paints{width:10px;height:10px;background:#00ff00}'), 'background-paints-emits-background');

    $imageScaleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Scale Fixture',
        'assets' => array(
            'fill-image'           => array('mime_type' => 'image/png', 'content' => 'fill image'),
            'stretch-image'        => array('mime_type' => 'image/png', 'content' => 'stretch image'),
            'featured-crop-image'  => array('mime_type' => 'image/png', 'content' => 'featured crop image'),
            'featured-crop-rect'   => array('mime_type' => 'image/png', 'content' => 'featured crop rect image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'scale:fill',
                'type'       => 'RECTANGLE',
                'name'       => 'Fill image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array('type' => 'IMAGE', 'imageRef' => 'fill-image', 'imageScaleMode' => 'FILL', 'imageShouldColorManage' => true, 'originalImageWidth' => 200, 'originalImageHeight' => 100),
                ),
            ),
            array(
                'id'         => 'scale:stretch',
                'type'       => 'RECTANGLE',
                'name'       => 'Stretch image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array('type' => 'IMAGE', 'imageRef' => 'stretch-image', 'imageScaleMode' => 'STRETCH'),
                ),
            ),
            array(
                'id'         => 'scale:featured-crop',
                'type'       => 'FRAME',
                'name'       => 'Featured image wrapper',
                'width'      => 691,
                'height'     => 345.5,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'featured-crop-image',
                        'imageScaleMode' => 'FILL',
                        'imageTransform' => array(
                            array(0.5, 0, 0.25),
                            array(0, 0.8, 0.1),
                        ),
                    ),
                ),
            ),
            array(
                'id'         => 'scale:featured-crop-rect',
                'type'       => 'FRAME',
                'name'       => 'Featured image crop rect wrapper',
                'width'      => 376,
                'height'     => 282,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'featured-crop-rect',
                        'imageScaleMode' => 'FILL',
                        'cropRect'       => array('x' => 0.1, 'y' => 0.2, 'width' => 0.8, 'height' => 0.5),
                    ),
                ),
            ),
        ),
    ));
    $imageScaleCss = $fileContent($imageScaleResult, 'style.css');
    $assert(str_contains($imageScaleCss, '.figma-node-scale-fill-fill-image{width:100px;height:80px;background-image:url("assets/fill-image.png");background-size:cover;background-position:center}'), 'image-fill-emits-cover-background');
    $assert(str_contains($imageScaleCss, '.figma-node-scale-stretch-stretch-image{width:100px;height:80px;background-image:url("assets/stretch-image.png");background-size:100% 100%;background-repeat:no-repeat;background-position:center}'), 'image-stretch-emits-stretch-background');
    $assert(str_contains($imageScaleCss, '.figma-node-scale-featured-crop-featured-image-wrapper{width:691px;height:345.5px;background-image:url("assets/featured-crop-image.png");background-size:1382px 431.875px;background-repeat:no-repeat;background-position:-345.5px -43.188px}'), 'image-fill-transform-emits-featured-background-crop');
    $assert(str_contains($imageScaleCss, '.figma-node-scale-featured-crop-rect-featured-image-crop-rect-wrapper{width:376px;height:282px;background-image:url("assets/featured-crop-rect.png");background-size:470px 564px;background-repeat:no-repeat;background-position:-47px -112.8px}'), 'image-fill-crop-rect-emits-featured-background-crop');
    $imageScaleVisualNodes = $imageScaleResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $imageScaleFillVisualNode = null;
    foreach ( is_array($imageScaleVisualNodes) ? $imageScaleVisualNodes : array() as $visualNode ) {
        if ( is_array($visualNode) && 'scale:fill' === ($visualNode['id'] ?? null) ) {
            $imageScaleFillVisualNode = $visualNode;
            break;
        }
    }
    $assert('FILL' === ($imageScaleFillVisualNode['image']['scale_mode'] ?? null), 'visual-node-image-scale-mode');
    $assert(true === ($imageScaleFillVisualNode['image']['color_managed'] ?? null), 'visual-node-image-color-managed');
    $assert(200.0 === ($imageScaleFillVisualNode['image']['originalImageWidth'] ?? null), 'visual-node-image-original-width');

    $assetRefResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Asset Ref Fixture',
        'assets' => array(
            array('id' => 'asset-ref-image', 'key' => 'asset-ref-key', 'mime_type' => 'image/png', 'content' => 'asset ref image'),
            array('id' => 'nested-ref-image', 'mime_type' => 'image/jpeg', 'content' => 'nested ref image'),
            array('id' => 'source-hash-image', 'hash' => 'source-image-hash', 'mime_type' => 'image/webp', 'content' => 'source hash image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'assetref:paint',
                'type'       => 'RECTANGLE',
                'name'       => 'Paint asset ref',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'IMAGE', 'assetRef' => array('key' => 'asset-ref-key'))),
            ),
            array(
                'id'         => 'assetref:nested',
                'type'       => 'RECTANGLE',
                'name'       => 'Nested asset ref',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'IMAGE', 'image' => array('assetRef' => array('id' => 'nested-ref-image')))),
            ),
            array(
                'id'         => 'assetref:source',
                'type'       => 'RECTANGLE',
                'name'       => 'Source hash image',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'IMAGE', 'sourceImage' => array('hash' => 'source-image-hash'))),
            ),
        ),
    ));
    $assetRefCss = $fileContent($assetRefResult, 'style.css');
    $assetRefSourceRefs = $assetRefResult['source_reports']['figma']['scenegraph']['asset_references'] ?? array();
    $assetRefSourceKeys = array_map(static fn (array $reference): string => (string) ($reference['source_key'] ?? ''), is_array($assetRefSourceRefs) ? $assetRefSourceRefs : array());
    $assert(str_contains($assetRefCss, 'background-image:url("assets/asset-ref-image.png")'), 'paint-asset-ref-key-resolves-asset');
    $assert(str_contains($assetRefCss, 'background-image:url("assets/nested-ref-image.jpg")'), 'nested-image-asset-ref-id-resolves-asset');
    $assert(str_contains($assetRefCss, 'background-image:url("assets/source-hash-image.webp")'), 'source-image-hash-resolves-asset');
    $assert(in_array('assetRef.key', $assetRefSourceKeys, true), 'scenegraph-reports-paint-asset-ref-key');
    $assert(in_array('image.assetRef.id', $assetRefSourceKeys, true), 'scenegraph-reports-nested-image-asset-ref-id');
    $assert(in_array('sourceImage.hash', $assetRefSourceKeys, true), 'scenegraph-reports-source-image-hash');

    $missingAssetRefResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Missing Asset Ref Fixture',
        'nodes' => array(
            array(
                'id'         => 'assetref:missing',
                'type'       => 'RECTANGLE',
                'name'       => 'Missing asset ref',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'IMAGE', 'assetRef' => array('fileKey' => 'missing-file-key'))),
            ),
        ),
    ));
    $missingAssets = $missingAssetRefResult['source_reports']['figma']['html']['transform_diagnostics']['images']['missing_assets'] ?? array();
    $missingRefs = is_array($missingAssets[0]['refs'] ?? null) ? $missingAssets[0]['refs'] : array();
    $assert(in_array('missing-file-key', $missingRefs, true), 'missing-asset-ref-reports-reference');

    $imageTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Transform Fixture',
        'assets' => array(
            'crop-image' => array('mime_type' => 'image/png', 'content' => 'crop image'),
            'fill-crop'  => array('mime_type' => 'image/png', 'content' => 'fill image'),
            'crop-rect'  => array('mime_type' => 'image/png', 'content' => 'crop rect image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'image:crop',
                'type'       => 'RECTANGLE',
                'name'       => 'Cropped image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'crop-image',
                        'imageScaleMode' => 'STRETCH',
                        'transform'      => array(
                            array(0.5, 0, 0.25),
                            array(0, 0.8, 0.1),
                        ),
                    ),
                ),
            ),
            array(
                'id'         => 'image:fill-crop',
                'type'       => 'RECTANGLE',
                'name'       => 'Fill crop image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'fill-crop',
                        'imageScaleMode' => 'FILL',
                        'transform'      => array(
                            array(0.5, 0, 0.25),
                            array(0, 0.8, 0.1),
                        ),
                    ),
                ),
            ),
            array(
                'id'         => 'image:crop-rect',
                'type'       => 'RECTANGLE',
                'name'       => 'Crop rect image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'crop-rect',
                        'imageScaleMode' => 'STRETCH',
                        'cropRect'       => array('x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.8),
                    ),
                ),
            ),
        ),
    ));
    $imageTransformCss = $fileContent($imageTransformResult, 'style.css');
    $cropRectVisualNode = blocks_engine_figma_transformer_contract_find_visual_node($imageTransformResult, 'image:crop-rect');
    $assert(str_contains($imageTransformCss, '.figma-node-image-crop-cropped-image{width:100px;height:80px;background-image:url("assets/crop-image.png");background-size:200px 100px;background-repeat:no-repeat;background-position:-50px -10px}'), 'image-stretch-transform-emits-crop-background');
    $assert(str_contains($imageTransformCss, '.figma-node-image-fill-crop-fill-crop-image{width:100px;height:80px;background-image:url("assets/fill-crop.png");background-size:200px 100px;background-repeat:no-repeat;background-position:-50px -10px}'), 'image-fill-transform-emits-crop-background');
    $assert(str_contains($imageTransformCss, '.figma-node-image-crop-rect-crop-rect-image{width:100px;height:80px;background-image:url("assets/crop-rect.png");background-size:200px 100px;background-repeat:no-repeat;background-position:-50px -10px}'), 'image-stretch-crop-rect-emits-crop-background');
    $assert(true === ($cropRectVisualNode['image']['has_crop_rect'] ?? null), 'visual-node-image-crop-rect-flag');
    $assert(0.5 === ($cropRectVisualNode['image']['crop_rect']['width'] ?? null), 'visual-node-image-crop-rect-width');

    $fullBleedImageTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Full Bleed Image Transform Fixture',
        'assets' => array(
            'hero-crop' => array('mime_type' => 'image/png', 'content' => 'hero crop image'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'fullbleed:root',
                'type'     => 'FRAME',
                'name'     => 'Full bleed page',
                'width'    => 1440,
                'height'   => 720,
                'layout'   => array('freeform' => true, 'display' => 'flex', 'flex_direction' => 'column'),
                'children' => array(
                    array(
                        'id'         => 'fullbleed:hero-image',
                        'type'       => 'RECTANGLE',
                        'name'       => 'Hero crop image',
                        'width'      => 1440,
                        'height'     => 590,
                        'x'          => 0,
                        'y'          => 120,
                        'layout'     => array('positioning' => 'absolute'),
                        'fillPaints' => array(
                            array(
                                'type'           => 'IMAGE',
                                'imageRef'       => 'hero-crop',
                                'imageScaleMode' => 'FILL',
                                'imageTransform' => array(
                                    array(0.5, 0, 0),
                                    array(0, 0.8, 0.1),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $fullBleedImageTransformCss = $fileContent($fullBleedImageTransformResult, 'style.css');
    $assert(str_contains($fullBleedImageTransformCss, '.figma-node-fullbleed-hero-image-hero-crop-image{width:100vw;height:590px;position:absolute;top:120px;left:50%;margin-left:-50vw;background-image:url("assets/hero-crop.png");background-size:calc(100vw * 2) calc(100vw * 0.512);background-repeat:no-repeat;background-position:calc(100vw * 0) calc(100vw * -0.051)'), 'full-bleed-image-transform-scales-crop-to-viewport');
    $assert(! str_contains($fullBleedImageTransformCss, '.figma-node-fullbleed-hero-image-hero-crop-image{width:100vw;height:590px;position:absolute;left:0px;'), 'full-bleed-image-transform-drops-source-left-before-breakout');

    $mirroredFullBleedImageTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Mirrored Full Bleed Image Transform Fixture',
        'assets' => array(
            'hero-mirror' => array('mime_type' => 'image/png', 'content' => 'hero mirror image'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'mirrorfullbleed:root',
                'type'     => 'FRAME',
                'name'     => 'Mirrored full bleed page',
                'width'    => 1440,
                'height'   => 720,
                'layout'   => array('freeform' => true, 'display' => 'flex', 'flex_direction' => 'column'),
                'children' => array(
                    array(
                        'id'         => 'mirrorfullbleed:hero-image',
                        'type'       => 'RECTANGLE',
                        'name'       => 'Mirrored hero crop image',
                        'width'      => 1440,
                        'height'     => 590,
                        'x'          => 1440,
                        'y'          => 120,
                        'figma_box'  => array('transform' => array(array(-1.0, 0.0, 0.0), array(0.0, 1.0, 0.0))),
                        'layout'     => array('positioning' => 'absolute'),
                        'fillPaints' => array(
                            array(
                                'type'           => 'IMAGE',
                                'imageRef'       => 'hero-mirror',
                                'imageScaleMode' => 'FILL',
                                'imageTransform' => array(
                                    array(0.5, 0, 0),
                                    array(0, 0.8, 0.1),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $mirroredFullBleedImageTransformCss = $fileContent($mirroredFullBleedImageTransformResult, 'style.css');
    $assert(str_contains($mirroredFullBleedImageTransformCss, '.figma-node-mirrorfullbleed-hero-image-mirrored-hero-crop-image{width:100vw;height:590px;position:absolute;top:120px;left:50%;margin-left:-50vw;transform:matrix(-1,0,0,1,0,0);transform-origin:50% 50%;background-image:url("assets/hero-mirror.png");background-size:calc(100vw * 2) calc(100vw * 0.512);background-repeat:no-repeat;background-position:calc(100vw * 0) calc(100vw * -0.051)'), 'mirrored-full-bleed-image-transform-mirrors-inside-viewport-breakout');
    $assert(! str_contains($mirroredFullBleedImageTransformCss, 'margin-left:50vw'), 'mirrored-full-bleed-image-transform-avoids-offcanvas-end-anchor');

    $layeredImageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Layered Image Paint Fixture',
        'assets' => array(
            'photo-layer' => array('mime_type' => 'image/png', 'content' => 'photo layer'),
            'wash-layer'  => array('mime_type' => 'image/png', 'content' => 'wash layer'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'image:layered-paints',
                'type'       => 'RECTANGLE',
                'name'       => 'Layered image paints',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'photo-layer',
                        'imageScaleMode' => 'STRETCH',
                        'cropRect'       => array('x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.8),
                    ),
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'wash-layer',
                        'imageScaleMode' => 'FILL',
                        'blendMode'      => 'MULTIPLY',
                        'imageTransform' => array(
                            array(0.25, 0, 0.5),
                            array(0, 0.5, 0.25),
                        ),
                    ),
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'photo-layer',
                        'imageScaleMode' => 'FILL',
                        'blendMode'      => 'SCREEN',
                        'imageTransform' => array(
                            array(0.5, 0, 0),
                            array(0, 0.25, 0.5),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $layeredImageCss = $fileContent($layeredImageResult, 'style.css');
    $assert(str_contains($layeredImageCss, 'background-image:url("assets/photo-layer.png"),url("assets/wash-layer.png"),url("assets/photo-layer.png")'), 'image-layered-paints-preserve-duplicate-top-to-bottom-order');
    $assert(str_contains($layeredImageCss, 'background-blend-mode:screen,multiply,normal'), 'image-layered-paints-align-blend-modes');
    $assert(str_contains($layeredImageCss, 'background-size:200px 320px,400px 160px,200px 100px'), 'image-layered-paints-align-background-size');
    $assert(str_contains($layeredImageCss, 'background-repeat:no-repeat,no-repeat,no-repeat'), 'image-layered-paints-align-background-repeat');
    $assert(str_contains($layeredImageCss, 'background-position:0px -160px,-200px -40px,-50px -10px'), 'image-layered-paints-align-background-position');

    $imageGradientStackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Gradient Stack Fixture',
        'assets' => array(
            'photo-layer' => array('mime_type' => 'image/png', 'content' => 'photo'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'image:gradient-stack',
                'type'       => 'RECTANGLE',
                'name'       => 'Image plus gradient stack',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array('type' => 'IMAGE', 'imageRef' => 'photo-layer', 'imageScaleMode' => 'FILL'),
                    array(
                        'type'  => 'GRADIENT_LINEAR',
                        'stops' => array(
                            array('position' => 0, 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 0.5)),
                            array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.5)),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $imageGradientStackCss = $fileContent($imageGradientStackResult, 'style.css');
    $assert(str_contains($imageGradientStackCss, 'background-image:linear-gradient(') && str_contains($imageGradientStackCss, '),url("assets/photo-layer.png")'), 'image-gradient-stack-preserves-gradient-and-image-layers');
    $assert(str_contains($imageGradientStackCss, 'background-size:100% 100%,cover'), 'image-gradient-stack-aligns-background-size-layers');

    $nestedImageOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Image Override Fixture',
        'nodes' => array(
            array(
                'guid'     => array('sessionID' => 700, 'localID' => 1),
                'type'     => 'COMPONENT',
                'name'     => 'Image component',
                'children' => array(
                    array(
                        'guid'       => array('sessionID' => 700, 'localID' => 2),
                        'type'       => 'RECTANGLE',
                        'name'       => 'Image',
                        'width'      => 100,
                        'height'     => 50,
                        'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'default-image')),
                    ),
                ),
            ),
            array(
                'guid'     => array('sessionID' => 700, 'localID' => 3),
                'type'     => 'COMPONENT',
                'name'     => 'Preview component',
                'children' => array(
                    array(
                        'guid'       => array('sessionID' => 700, 'localID' => 4),
                        'type'       => 'INSTANCE',
                        'name'       => 'Image slot',
                        'symbolData' => array('symbolID' => array('sessionID' => 700, 'localID' => 1)),
                    ),
                ),
            ),
            array(
                'id'         => 'instance:preview',
                'type'       => 'INSTANCE',
                'name'       => 'Preview instance',
                'symbolData' => array(
                    'symbolID' => array('sessionID' => 700, 'localID' => 3),
                    'symbolOverrides' => array(
                        array(
                            'guidPath'   => array('guids' => array(array('sessionID' => 700, 'localID' => 4), array('sessionID' => 700, 'localID' => 2))),
                            'fillPaints' => array(
                                array('type' => 'IMAGE', 'imageRef' => 'default-image'),
                                array(
                                    'type'                   => 'IMAGE',
                                    'imageRef'               => 'override-image',
                                    'imageScaleMode'         => 'STRETCH',
                                    'imageShouldColorManage' => false,
                                    'rotation'               => 15,
                                    'scale'                  => 2,
                                    'animationFrame'         => 3,
                                    'thumbHash'              => 'thumbhash-bytes',
                                    'imageTransform'         => array(
                                        array(0.5, 0, 0.25),
                                        array(0, 0.5, 0.25),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        'assets' => array(
            array('id' => 'default-image', 'content' => 'default'),
            array('id' => 'override-image', 'content' => 'override'),
        ),
    ));
    $nestedImageOverrideCss = $fileContent($nestedImageOverrideResult, 'style.css');
    $nestedImageOverrideVisualNode = blocks_engine_figma_transformer_contract_find_visual_node($nestedImageOverrideResult, 'instance:preview/700:4/700:2');
    $assert(str_contains($nestedImageOverrideCss, '.figma-node-instance-preview-700-4-700-2-image'), 'nested-image-override-emits-nested-image-node');
    $assert(str_contains($nestedImageOverrideCss, 'background-image:url("assets/override-image.bin")'), 'nested-image-override-replaces-source-image-paint');
    $assert(true === ($nestedImageOverrideVisualNode['image']['has_transform'] ?? null), 'nested-image-override-carries-image-transform-metadata');
    $assert(15.0 === ($nestedImageOverrideVisualNode['image']['rotation'] ?? null), 'nested-image-override-carries-image-rotation');
    $assert(2.0 === ($nestedImageOverrideVisualNode['image']['scale'] ?? null), 'nested-image-override-carries-image-scale');
    $assert(3 === ($nestedImageOverrideVisualNode['image']['animationFrame'] ?? null), 'nested-image-override-carries-animation-frame');
    $assert('thumbhash-bytes' === ($nestedImageOverrideVisualNode['image']['thumbHash'] ?? null), 'nested-image-override-carries-thumb-hash');
    $assert(! str_contains($nestedImageOverrideCss, 'background-image:url("assets/override-image.bin"),url("assets/default-image.bin")'), 'nested-image-override-drops-stale-source-image-paint');

    $styleBackedImageOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Style Backed Image Override Fixture',
        'nodes' => array(
            array(
                'guid'       => array('sessionID' => 701, 'localID' => 1),
                'type'       => 'RECTANGLE',
                'name'       => 'Image style source',
                'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'styled-default-image')),
            ),
            array(
                'guid'     => array('sessionID' => 701, 'localID' => 2),
                'type'     => 'COMPONENT',
                'name'     => 'Styled image component',
                'children' => array(
                    array(
                        'guid'           => array('sessionID' => 701, 'localID' => 3),
                        'type'           => 'RECTANGLE',
                        'name'           => 'Styled image',
                        'width'          => 100,
                        'height'         => 50,
                        'styleIdForFill' => array('guid' => array('sessionID' => 701, 'localID' => 1)),
                    ),
                ),
            ),
            array(
                'id'         => 'instance:styled-preview',
                'type'       => 'INSTANCE',
                'name'       => 'Styled preview instance',
                'symbolData' => array(
                    'symbolID' => array('sessionID' => 701, 'localID' => 2),
                    'symbolOverrides' => array(
                        array(
                            'guidPath'   => array('guids' => array(array('sessionID' => 701, 'localID' => 3))),
                            'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'styled-override-image')),
                        ),
                    ),
                ),
            ),
        ),
        'assets' => array(
            array('id' => 'styled-default-image', 'content' => 'styled default'),
            array('id' => 'styled-override-image', 'content' => 'styled override'),
        ),
    ));
    $styleBackedImageOverrideCss = $fileContent($styleBackedImageOverrideResult, 'style.css');
    $assert(str_contains($styleBackedImageOverrideCss, 'background-image:url("assets/styled-override-image.bin")'), 'style-backed-image-override-replaces-style-image-paint');
    $assert(! str_contains($styleBackedImageOverrideCss, 'styled-default-image.bin'), 'style-backed-image-override-drops-stale-style-image-paint');

    $paintMetadataResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Paint Metadata Fixture',
        'nodes' => array(
            array(
                'id'         => 'paint-style:gradient-transform',
                'type'       => 'RECTANGLE',
                'name'       => 'Gradient Transform Style',
                'styleType'  => 'FILL',
                'fillPaints' => array(
                    array(
                        'type'      => 'GRADIENT_LINEAR',
                        'opacity'   => 0.5,
                        'blendMode' => 'MULTIPLY',
                        'stops'     => array(
                            array('position' => 0, 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 1)),
                            array('position' => 1, 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)),
                        ),
                        'transform' => array(
                            array(0, 1, 0),
                            array(-1, 0, 1),
                        ),
                    ),
                ),
            ),
            array(
                'id'         => 'paint-style:stroke-blue',
                'type'       => 'RECTANGLE',
                'name'       => 'Blue Stroke Style',
                'styleType'  => 'FILL',
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1))),
            ),
            array(
                'id'              => 'paint:metadata-frame',
                'type'            => 'FRAME',
                'name'            => 'Paint Metadata Frame',
                'width'           => 80,
                'height'          => 40,
                'styleIdForFill'  => 'paint-style:gradient-transform',
                'fillPaints'      => array(array('type' => 'SOLID', 'visible' => false, 'color' => array('r' => 0, 'g' => 1, 'b' => 0, 'a' => 1))),
                'backgroundPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 0, 'a' => 1), 'opacity' => 0.25)),
                'blendMode'       => 'PASS_THROUGH',
            ),
            array(
                'guid'                  => array('sessionID' => 702, 'localID' => 1),
                'type'                  => 'COMPONENT',
                'name'                  => 'Stroke Style Component',
                'children'              => array(
                    array(
                        'guid'                 => array('sessionID' => 702, 'localID' => 2),
                        'type'                 => 'RECTANGLE',
                        'name'                 => 'Stroke Style Child',
                        'width'                => 20,
                        'height'               => 20,
                        'styleIdForStrokeFill' => 'paint-style:stroke-blue',
                        'strokeWeight'         => 2,
                        'strokeAlign'          => 'INSIDE',
                    ),
                ),
            ),
            array(
                'id'         => 'instance:stroke-style',
                'type'       => 'INSTANCE',
                'name'       => 'Stroke Style Instance',
                'symbolData' => array('symbolID' => array('sessionID' => 702, 'localID' => 1)),
            ),
        ),
    ));
    $paintMetadataCss = $fileContent($paintMetadataResult, 'style.css');
    $paintMetadataFrame = blocks_engine_figma_transformer_contract_find_visual_node($paintMetadataResult, 'paint:metadata-frame');
    $strokeStyleChild = blocks_engine_figma_transformer_contract_find_visual_node($paintMetadataResult, 'instance:stroke-style/702:2');
    $assert(str_contains($paintMetadataCss, '.figma-node-paint-metadata-frame-paint-metadata-frame{width:80px;height:40px;background:linear-gradient(') && str_contains($paintMetadataCss, 'rgba(255,0,0,0.5) 0%,rgba(0,0,255,0.5) 100%'), 'style-gradient-transform-wins-over-invisible-local-fill');
    $assert(! str_contains($paintMetadataCss, '#00ff00'), 'invisible-local-fill-omitted');
    $assert(0.25 === ($paintMetadataFrame['paints']['background'][0]['opacity'] ?? null), 'visual-node-carries-background-paint-opacity');
    $assert('GRADIENT_LINEAR' === ($paintMetadataFrame['paints']['fills'][0]['type'] ?? null), 'visual-node-carries-style-fill-paint-type');
    $assert(0.5 === ($paintMetadataFrame['paints']['fills'][0]['opacity'] ?? null), 'visual-node-carries-style-fill-opacity');
    $assert('MULTIPLY' === ($paintMetadataFrame['paints']['fills'][0]['blendMode'] ?? null), 'visual-node-carries-style-fill-blend-mode');
    $assert(array(array(0, 1, 0), array(-1, 0, 1)) === ($paintMetadataFrame['paints']['fills'][0]['gradientTransform'] ?? null), 'visual-node-carries-gradient-transform-from-transform-field');
    $assert('SOLID' === ($strokeStyleChild['paints']['strokes'][0]['type'] ?? null), 'cloned-instance-carries-stroke-style-paint');
    $assert(str_contains($paintMetadataCss, 'border:2px solid #0000ff'), 'cloned-instance-stroke-style-emits-border');
}

<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphPagePlanner;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_site_generation_quality_contract(callable $assert, callable $fileContent, callable $artifactQualitySignalCodes, callable $artifactQualitySignal): void
{
    $qualityAssets = array();
    for ( $i = 1; $i <= 20; $i++ ) {
        $qualityAssets['quality-image-' . $i] = array('mime_type' => 'image/png', 'content' => 'image ' . $i);
    }
    $qualityChildren = array(
        array(
            'id'       => 'quality:header',
            'type'     => 'GROUP',
            'name'     => 'Site Header Raster Group',
            'width'    => 1440,
            'height'   => 120,
            'children' => array(
                array('id' => 'quality:header:1', 'type' => 'RECTANGLE', 'name' => 'Header slice 1', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-1'),
                array('id' => 'quality:header:2', 'type' => 'RECTANGLE', 'name' => 'Header slice 2', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-2'),
                array('id' => 'quality:header:3', 'type' => 'RECTANGLE', 'name' => 'Header slice 3', 'width' => 300, 'height' => 80, 'asset_id' => 'quality-image-3'),
            ),
        ),
        array('id' => 'quality:offcanvas', 'type' => 'RECTANGLE', 'name' => 'Off canvas promo', 'x' => 2000, 'y' => -180, 'width' => 200, 'height' => 100, 'layoutPositioning' => 'ABSOLUTE', 'asset_id' => 'quality-image-4'),
    );
    for ( $i = 5; $i <= 12; $i++ ) {
        $qualityChildren[] = array('id' => 'quality:image:' . $i, 'type' => 'RECTANGLE', 'name' => 'Raster card ' . $i, 'width' => 80, 'height' => 60, 'asset_id' => 'quality-image-' . $i);
    }
    for ( $i = 13; $i <= 20; $i++ ) {
        $qualityChildren[] = array('id' => 'quality:vector:' . $i, 'type' => 'VECTOR', 'name' => 'Vector fallback ' . $i, 'width' => 24, 'height' => 24, 'asset_id' => 'quality-image-' . $i);
    }
    $qualityDiagnosticsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Quality Diagnostics Fixture',
        'assets' => $qualityAssets,
        'nodes'  => array(
            array(
                'id'       => 'quality:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop fixed root',
                'width'    => 1440,
                'height'   => 1200,
                'layoutMode' => 'VERTICAL',
                'children' => $qualityChildren,
            ),
        ),
    ));
    $qualitySignalCodes = $artifactQualitySignalCodes($qualityDiagnosticsResult);
    $assert(! in_array('fixed_root_width', $qualitySignalCodes, true), 'quality-diagnostics-fixed-root-width-retired');
    $qualityCss = $fileContent($qualityDiagnosticsResult, 'style.css');
    $assert(str_contains($qualityCss, '.figma-node-quality-root-desktop-fixed-root{width:100%;height:1200px;'), 'quality-diagnostics-root-renders-fluid-full-bleed');
    $assert(! str_contains($qualityCss, '.figma-node-quality-root-desktop-fixed-root{width:100%;max-width:1440px;margin-left:auto;margin-right:auto;'), 'quality-diagnostics-root-avoids-letterbox-max-width');

    $explicitMaxWidthResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Explicit Root Max Width Fixture',
        'nodes' => array(
            array(
                'id'       => 'explicit-max:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop constrained root',
                'width'    => 1440,
                'height'   => 900,
                'maxWidth' => 1200,
            ),
        ),
    ));
    $explicitMaxWidthCss = $fileContent($explicitMaxWidthResult, 'style.css');
    $assert(str_contains($explicitMaxWidthCss, '.figma-node-explicit-max-root-desktop-constrained-root{width:100%;height:900px;max-width:1200px'), 'quality-diagnostics-root-honors-explicit-max-width');

    $fullWidthBandResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Full Width Band Fixture',
        'nodes' => array(
            array(
                'id'       => 'fluid-band:root',
                'type'     => 'FRAME',
                'name'     => 'Desktop page',
                'width'    => 1440,
                'height'   => 1200,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'       => 'fluid-band:hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero band',
                        'width'    => 1440,
                        'height'   => 520,
                        'children' => array(
                            array('id' => 'fluid-band:title', 'type' => 'TEXT', 'name' => 'Hero title', 'characters' => 'Fluid hero', 'width' => 320, 'height' => 48, 'fontSize' => 36),
                        ),
                    ),
                    array(
                        'id'       => 'fluid-band:card',
                        'type'     => 'FRAME',
                        'name'     => 'Narrow card',
                        'width'    => 420,
                        'height'   => 240,
                    ),
                ),
            ),
        ),
    ));
    $fullWidthBandCss = $fileContent($fullWidthBandResult, 'style.css');
    $assert(str_contains($fullWidthBandCss, '.figma-node-fluid-band-hero-hero-band{width:100%;height:520px;'), 'quality-diagnostics-full-width-band-renders-fluid');
    $assert(str_contains($fullWidthBandCss, '.figma-node-fluid-band-card-narrow-card{width:420px;height:240px;'), 'quality-diagnostics-narrow-band-keeps-intrinsic-width');
    $assert(in_array('large_absolute_offsets', $qualitySignalCodes, true), 'quality-diagnostics-large-absolute-offsets');
    $assert(in_array('large_css_offsets', $qualitySignalCodes, true), 'quality-diagnostics-large-css-offsets');
    $assert(in_array('image_heavy_landmark_candidate', $qualitySignalCodes, true), 'quality-diagnostics-image-heavy-landmark');
    $assert(in_array('excessive_image_blocks', $qualitySignalCodes, true), 'quality-diagnostics-excessive-image-blocks');
    $assert(in_array('excessive_vector_image_fallbacks', $qualitySignalCodes, true), 'quality-diagnostics-excessive-vector-fallbacks');
    $largeOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'large_absolute_offsets');
    $assert('quality:offcanvas' === ($largeOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-node');
    $assert('quality:root' === ($largeOffsetSignal['sample_nodes'][0]['parent_id'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-parent');
    $assert('figma-node-quality-offcanvas-off-canvas-promo' === ($largeOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-large-absolute-offset-sample-class');
    $assert(2000.0 === (float) ($largeOffsetSignal['sample_nodes'][0]['left'] ?? 0), 'quality-diagnostics-large-absolute-offset-sample-left');
    $largeCssOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'large_css_offsets');
    $assert('quality:offcanvas' === ($largeCssOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-large-css-offset-sample-node');
    $assert('figma-node-quality-offcanvas-off-canvas-promo' === ($largeCssOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-large-css-offset-sample-class');
    $assert(2000.0 === (float) ($largeCssOffsetSignal['sample_nodes'][0]['left'] ?? 0), 'quality-diagnostics-large-css-offset-sample-left');
    $visualOffsetSignal = $artifactQualitySignal($qualityDiagnosticsResult, 'off_canvas_visual_nodes');
    $assert('quality:offcanvas' === ($visualOffsetSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-off-canvas-visual-sample-node');
    $assert('figma-node-quality-offcanvas-off-canvas-promo' === ($visualOffsetSignal['sample_nodes'][0]['class'] ?? null), 'quality-diagnostics-off-canvas-visual-sample-class');
    $assert('needs_review' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['status'] ?? null), 'quality-diagnostics-status-needs-review');
    $assert('warn' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['quality_status'] ?? null), 'quality-diagnostics-quality-status-warn');
    $qualitySignals = $qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
    $excessiveImageSignal = null;
    foreach ( is_array($qualitySignals) ? $qualitySignals : array() as $signal ) {
        if ( is_array($signal) && 'excessive_image_blocks' === ($signal['code'] ?? null) ) {
            $excessiveImageSignal = $signal;
            break;
        }
    }
    $assert(12 === ($excessiveImageSignal['threshold'] ?? null), 'quality-diagnostics-excessive-image-threshold');
    $assert(($excessiveImageSignal['image_node_density'] ?? 0) > 0.35, 'quality-diagnostics-excessive-image-density');
    $assert(! empty($excessiveImageSignal['sample_nodes']) && count($excessiveImageSignal['sample_nodes']) <= 10, 'quality-diagnostics-excessive-image-samples');
    $assert(20 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary']['image_block_count'] ?? null), 'quality-diagnostics-image-block-summary-count');
    $assert(22 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary']['total_node_count'] ?? null), 'quality-diagnostics-total-node-summary-count');
    $assert('quality:root' === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['selection']['selected_frames'][0]['frame_id'] ?? null), 'quality-diagnostics-selected-frame-id');
    $assert(22 === ($qualityDiagnosticsResult['source_reports']['figma']['html']['transform_diagnostics']['selection']['selected_frames'][0]['node_count'] ?? null), 'quality-diagnostics-selected-frame-node-count');
    
    $normalImageAssets = array();
    $normalImageChildren = array();
    for ( $i = 1; $i <= 12; $i++ ) {
        $normalImageAssets['normal-image-' . $i] = array('mime_type' => 'image/png', 'content' => 'normal image ' . $i);
        $normalImageChildren[] = array('id' => 'normal:image:' . $i, 'type' => 'RECTANGLE', 'name' => 'Gallery image ' . $i, 'width' => 120, 'height' => 90, 'asset_id' => 'normal-image-' . $i);
    }
    for ( $i = 1; $i <= 32; $i++ ) {
        $normalImageChildren[] = array('id' => 'normal:text:' . $i, 'type' => 'TEXT', 'name' => 'Body copy ' . $i, 'characters' => 'Paragraph ' . $i, 'fontSize' => 16);
    }
    $normalImageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Normal Image Count Fixture',
        'assets' => $normalImageAssets,
        'nodes'  => array(
            array(
                'id'       => 'normal:root',
                'type'     => 'FRAME',
                'name'     => 'Editorial gallery page',
                'width'    => 960,
                'height'   => 1600,
                'layoutMode' => 'VERTICAL',
                'children' => $normalImageChildren,
            ),
        ),
    ));
    $normalImageQuality = $normalImageResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality'] ?? array();
    $assert(! in_array('excessive_image_blocks', $artifactQualitySignalCodes($normalImageResult), true), 'quality-diagnostics-normal-image-count-no-excessive-signal');
    $assert(12 === ($normalImageQuality['summary']['image_block_count'] ?? null), 'quality-diagnostics-normal-image-count-summary');
    $assert(($normalImageQuality['summary']['image_node_density'] ?? 1) < 0.35, 'quality-diagnostics-normal-image-density-summary');
    
    $normalLocalPlacementResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Normal Local Placement Fixture',
        'nodes' => array(
            array(
                'id'       => 'normal-local:root',
                'type'     => 'FRAME',
                'name'     => 'Normal local root',
                'width'    => 480,
                'height'   => 320,
                'children' => array(
                    array('id' => 'normal-local:card', 'type' => 'FRAME', 'name' => 'Local card', 'x' => 40, 'y' => 60, 'width' => 180, 'height' => 90, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                        array('id' => 'normal-local:copy', 'type' => 'TEXT', 'name' => 'Card copy', 'characters' => 'Normal placement', 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $normalLocalSignalCodes = $artifactQualitySignalCodes($normalLocalPlacementResult);
    $assert(! in_array('large_absolute_offsets', $normalLocalSignalCodes, true), 'quality-diagnostics-normal-local-no-large-offset-signal');
    $assert(! in_array('off_canvas_visual_nodes', $normalLocalSignalCodes, true), 'quality-diagnostics-normal-local-no-visual-off-canvas-signal');
    $assert(0 === ($normalLocalPlacementResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['large_absolute_offset_count'] ?? null), 'quality-diagnostics-normal-local-large-offset-count-zero');
    $assert(0 === ($normalLocalPlacementResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['off_canvas_visual_node_count'] ?? null), 'quality-diagnostics-normal-local-visual-offset-count-zero');

    $absoluteChildReserveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Absolute Child Flow Reserve Fixture',
        'nodes' => array(
            array(
                'id'       => 'reserve:root',
                'type'     => 'FRAME',
                'name'     => 'Page root',
                'width'    => 640,
                'height'   => 700,
                'children' => array(
                    array(
                        'id'       => 'reserve:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer shell',
                        'x'        => 0,
                        'y'        => 120,
                        'width'    => 640,
                        'height'   => 483,
                        'children' => array(
                            array('id' => 'reserve:newsletter', 'type' => 'FRAME', 'name' => 'Promoted card', 'x' => 112, 'y' => 0, 'width' => 416, 'height' => 352, 'children' => array(
                                array('id' => 'reserve:newsletter:text', 'type' => 'TEXT', 'name' => 'Card heading', 'characters' => 'Promoted content', 'width' => 240, 'height' => 32, 'fontSize' => 24),
                            )),
                            array('id' => 'reserve:bottom', 'type' => 'FRAME', 'name' => 'Bottom bar', 'x' => 0, 'y' => 352, 'width' => 640, 'height' => 131, 'children' => array(
                                array('id' => 'reserve:bottom:text', 'type' => 'TEXT', 'name' => 'Bottom text', 'characters' => 'Footer links', 'width' => 120, 'height' => 24, 'fontSize' => 16),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $absoluteChildReserveCss = $fileContent($absoluteChildReserveResult, 'style.css');
    $assert(str_contains($absoluteChildReserveCss, '.figma-node-reserve-footer-footer-shell{') && str_contains($absoluteChildReserveCss, 'height:483px;min-height:483px;'), 'absolute-child-reserve-parent-min-height');
    $assert(str_contains($absoluteChildReserveCss, '.figma-node-reserve-newsletter-promoted-card{') && str_contains($absoluteChildReserveCss, 'width:416px;height:352px;position:absolute;left:112px;top:0px'), 'absolute-child-reserve-positioned-card-preserved');
    $assert(str_contains($absoluteChildReserveCss, '.figma-node-reserve-bottom-bottom-bar{') && str_contains($absoluteChildReserveCss, 'width:640px;height:131px;position:absolute;left:0px;top:352px'), 'absolute-child-reserve-positioned-bottom-preserved');

    $overlappingFooterLayerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Overlapping Footer Layer Fixture',
        'nodes' => array(
            array(
                'id'       => 'footer-layer:root',
                'type'     => 'FRAME',
                'name'     => 'Page root',
                'width'    => 640,
                'height'   => 700,
                'children' => array(
                    array(
                        'id'       => 'footer-layer:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer shell',
                        'x'        => 0,
                        'y'        => 120,
                        'width'    => 640,
                        'height'   => 483,
                        'children' => array(
                            array('id' => 'footer-layer:bottom', 'type' => 'FRAME', 'name' => 'Yellow footer area', 'x' => 0, 'y' => 300, 'width' => 640, 'height' => 183, 'parentIndex' => array('position' => 'a'), 'children' => array(
                                array('id' => 'footer-layer:bottom:text', 'type' => 'TEXT', 'name' => 'Bottom text', 'characters' => 'Footer links', 'width' => 120, 'height' => 24, 'fontSize' => 16),
                            )),
                            array('id' => 'footer-layer:newsletter', 'type' => 'FRAME', 'name' => 'Newsletter card', 'x' => 112, 'y' => 0, 'width' => 416, 'height' => 352, 'parentIndex' => array('position' => 'b'), 'children' => array(
                                array('id' => 'footer-layer:newsletter:text', 'type' => 'TEXT', 'name' => 'Card heading', 'characters' => 'Get the newsletter', 'width' => 240, 'height' => 32, 'fontSize' => 24),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $overlappingFooterLayerCss = $fileContent($overlappingFooterLayerResult, 'style.css');
    $assert(str_contains($overlappingFooterLayerCss, '.figma-node-footer-layer-newsletter-newsletter-card{') && str_contains($overlappingFooterLayerCss, 'position:absolute;left:112px;top:0px;z-index:2'), 'overlapping-footer-newsletter-layer-on-top');
    $assert(str_contains($overlappingFooterLayerCss, '.figma-node-footer-layer-bottom-yellow-footer-area{') && str_contains($overlappingFooterLayerCss, 'position:absolute;left:0px;top:300px;z-index:1'), 'overlapping-footer-bottom-layer-under-newsletter');

    $kiwiSourceGapReserveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Source Gap Reserve Fixture',
        'nodes' => array(
            array(
                'id'           => 'kiwi-gap:root',
                'type'         => 'FRAME',
                'name'         => 'Article template',
                'width'        => 960,
                'height'       => 720,
                'stackMode'    => 'VERTICAL',
                'stackSpacing' => 24,
                'children'     => array(
                    array('id' => 'kiwi-gap:read-next', 'type' => 'FRAME', 'name' => 'Read next section', 'width' => 960, 'height' => 200, 'transform' => array('m02' => 0, 'm12' => 0), 'children' => array(
                        array('id' => 'kiwi-gap:read-next:title', 'type' => 'TEXT', 'name' => 'Read next title', 'characters' => 'Read Next', 'width' => 160, 'height' => 32, 'fontSize' => 24),
                    )),
                    array('id' => 'kiwi-gap:footer', 'type' => 'FRAME', 'name' => 'Footer', 'width' => 960, 'height' => 120, 'transform' => array('m02' => 0, 'm12' => 280), 'children' => array(
                        array('id' => 'kiwi-gap:footer:text', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Footer links', 'width' => 140, 'height' => 24, 'fontSize' => 16),
                    )),
                ),
            ),
        ),
    ));
    $kiwiSourceGapReserveCss = $fileContent($kiwiSourceGapReserveResult, 'style.css');
    $assert(str_contains($kiwiSourceGapReserveCss, '.figma-node-kiwi-gap-root-article-template{') && str_contains($kiwiSourceGapReserveCss, 'display:flex;flex-direction:column;gap:24px'), 'kiwi-source-gap-reserve-parent-auto-layout-gap');
    $assert(str_contains($kiwiSourceGapReserveCss, '.figma-node-kiwi-gap-footer-footer{') && str_contains($kiwiSourceGapReserveCss, 'margin-top:56px'), 'kiwi-source-gap-reserve-footer-residual-margin');

    $stickyGhostResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sticky Ghost Fixture',
        'nodes' => array(
            array(
                'id'       => 'sticky:root',
                'type'     => 'FRAME',
                'name'     => 'Sticky page',
                'width'    => 960,
                'height'   => 1800,
                'children' => array(
                    array(
                        'id'         => 'sticky:row',
                        'type'       => 'FRAME',
                        'name'       => 'Article row',
                        'width'      => 960,
                        'height'     => 1600,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 24,
                        'children'   => array(
                            array('id' => 'sticky:toc-primary', 'type' => 'FRAME', 'name' => 'Table of contents', 'width' => 240, 'height' => 320, 'children' => array(
                                array('id' => 'sticky:toc-title', 'type' => 'TEXT', 'name' => 'TOC title', 'characters' => 'Contents', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                                array('id' => 'sticky:toc-link', 'type' => 'TEXT', 'name' => 'TOC link', 'characters' => 'Introduction', 'width' => 120, 'height' => 20, 'fontSize' => 14),
                            )),
                            array('id' => 'sticky:article', 'type' => 'FRAME', 'name' => 'Article body', 'width' => 696, 'height' => 1200, 'children' => array(
                                array('id' => 'sticky:article-title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 320, 'height' => 48, 'fontSize' => 36),
                            )),
                            array('id' => 'sticky:toc-ghost', 'type' => 'FRAME', 'name' => 'Table of contents', 'x' => 0, 'y' => 1280, 'width' => 240, 'height' => 320, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 0.1, 'children' => array(
                                array('id' => 'sticky:toc-ghost-title', 'type' => 'TEXT', 'name' => 'TOC title', 'characters' => 'Contents', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                                array('id' => 'sticky:toc-ghost-link', 'type' => 'TEXT', 'name' => 'TOC link', 'characters' => 'Introduction', 'width' => 120, 'height' => 20, 'fontSize' => 14),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $stickyGhostHtml = $fileContent($stickyGhostResult, 'index.html');
    $stickyGhostCss = $fileContent($stickyGhostResult, 'style.css');
    $stickyGhostLayout = $stickyGhostResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(str_contains($stickyGhostHtml, 'data-figma-node-id="sticky:toc-primary"'), 'sticky-ghost-primary-emitted');
    $assert(! str_contains($stickyGhostHtml, 'data-figma-node-id="sticky:toc-ghost"'), 'sticky-ghost-duplicate-suppressed');
    $assert(str_contains($stickyGhostCss, '.figma-node-sticky-toc-primary-table-of-contents{') && str_contains($stickyGhostCss, 'position:sticky;top:0;align-self:flex-start'), 'sticky-ghost-primary-css-sticky');
    $assert(1 === ($stickyGhostLayout['sticky_ghosts']['count'] ?? null), 'sticky-ghost-diagnostic-count');
    $assert('sticky:toc-primary' === ($stickyGhostLayout['sticky_ghosts']['candidates'][0]['primary_id'] ?? null), 'sticky-ghost-diagnostic-primary');
    $assert('sticky:toc-ghost' === ($stickyGhostLayout['sticky_ghosts']['candidates'][0]['ghost_id'] ?? null), 'sticky-ghost-diagnostic-ghost');

    $stickyGhostSourceMismatchResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sticky Ghost Source Mismatch Fixture',
        'nodes' => array(
            array(
                'id'       => 'sticky-real:root',
                'type'     => 'FRAME',
                'name'     => 'Blog Post Desktop',
                'width'    => 1440,
                'height'   => 4800,
                'clipsContent' => true,
                'children' => array(
                    array(
                        'id'         => '4194:2157',
                        'type'       => 'FRAME',
                        'name'       => 'Frame 51076550',
                        'width'      => 1216,
                        'height'     => 4163.5,
                        'clipsContent' => true,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 105,
                        'children'   => array(
                            array('id' => '4212:3087', 'figma_component_source_id' => '4212:3087', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'width' => 315, 'height' => 510, 'children' => array(
                                array('id' => '4212:3087/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                array('id' => '4212:3087/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4212:3087/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4212:3087/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                array('id' => '4212:3087/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                            )),
                            array('id' => 'sticky-real:article', 'type' => 'FRAME', 'name' => 'Article body', 'width' => 796, 'height' => 3000, 'clipsContent' => true, 'children' => array(
                                array('id' => 'sticky-real:title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 420, 'height' => 56, 'fontSize' => 42),
                            )),
                            array('id' => '4210:12595', 'figma_component_source_id' => '4210:12595', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'x' => 0, 'y' => 3654, 'width' => 315, 'height' => 510, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 0.1, 'children' => array(
                                array('id' => '4210:12595/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                array('id' => '4210:12595/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4210:12595/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                array('id' => '4210:12595/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                array('id' => '4210:12595/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $stickyGhostSourceMismatchHtml = $fileContent($stickyGhostSourceMismatchResult, 'index.html');
    $stickyGhostSourceMismatchCss = $fileContent($stickyGhostSourceMismatchResult, 'style.css');
    $stickyGhostSourceMismatchLayout = $stickyGhostSourceMismatchResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(str_contains($stickyGhostSourceMismatchHtml, 'data-figma-node-id="4212:3087"'), 'sticky-ghost-source-mismatch-primary-emitted');
    $assert(! str_contains($stickyGhostSourceMismatchHtml, 'data-figma-node-id="4210:12595"'), 'sticky-ghost-source-mismatch-duplicate-suppressed');
    $assert(str_contains($stickyGhostSourceMismatchCss, '.figma-node-4212-3087-table-of-contents{') && str_contains($stickyGhostSourceMismatchCss, 'position:sticky;top:0;align-self:flex-start'), 'sticky-ghost-source-mismatch-primary-css-sticky');
    $assert(! preg_match('/\.figma-node-sticky-real-root-blog-post-desktop\{[^}]*overflow:hidden/', $stickyGhostSourceMismatchCss), 'sticky-ghost-source-mismatch-root-no-overflow-hidden');
    $assert(! preg_match('/\.figma-node-4194-2157-frame-51076550\{[^}]*overflow:hidden/', $stickyGhostSourceMismatchCss), 'sticky-ghost-source-mismatch-row-no-overflow-hidden');
    $assert(1 === preg_match('/\.figma-node-sticky-real-article-article-body\{[^}]*overflow:hidden/', $stickyGhostSourceMismatchCss), 'sticky-ghost-source-mismatch-sibling-clipping-preserved');
    $assert(1 === ($stickyGhostSourceMismatchLayout['sticky_ghosts']['count'] ?? null), 'sticky-ghost-source-mismatch-diagnostic-count');
    $assert('4212:3087' === ($stickyGhostSourceMismatchLayout['sticky_ghosts']['candidates'][0]['primary_id'] ?? null), 'sticky-ghost-source-mismatch-diagnostic-primary');
    $assert('4210:12595' === ($stickyGhostSourceMismatchLayout['sticky_ghosts']['candidates'][0]['ghost_id'] ?? null), 'sticky-ghost-source-mismatch-diagnostic-ghost');

    $stickyGhostMultiPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sticky Ghost Multi Page Fixture',
        'nodes' => array(
            array(
                'id'       => 'sticky-multi:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'sticky-multi:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 1440,
                        'height'   => 900,
                        'children' => array(
                            array('id' => 'sticky-multi:home-title', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Home', 'width' => 180, 'height' => 48, 'fontSize' => 36),
                        ),
                    ),
                    array(
                        'id'       => 'sticky-multi:section',
                        'type'     => 'SECTION',
                        'name'     => 'Blog Post Section',
                        'children' => array(
                            array(
                                'id'       => 'sticky-multi:blog-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Blog Post Desktop',
                                'width'    => 1440,
                                'height'   => 4800,
                                'children' => array(
                                    array(
                                        'id'         => '4194:2157',
                                        'type'       => 'FRAME',
                                        'name'       => 'Frame 51076550',
                                        'width'      => 1216,
                                        'height'     => 4163.5,
                                        'layoutMode' => 'HORIZONTAL',
                                        'itemSpacing' => 105,
                                        'children'   => array(
                                            array('id' => '4212:3087', 'figma_component_source_id' => '4212:3087', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'width' => 315, 'height' => 510, 'children' => array(
                                                array('id' => '4212:3087/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                                array('id' => '4212:3087/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4212:3087/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4212:3087/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                                array('id' => '4212:3087/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                            )),
                                            array('id' => 'sticky-multi:article', 'type' => 'FRAME', 'name' => 'Article body', 'width' => 796, 'height' => 3000, 'children' => array(
                                                array('id' => 'sticky-multi:title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 420, 'height' => 56, 'fontSize' => 42),
                                            )),
                                            array('id' => '4210:12595', 'figma_component_source_id' => '4210:12595', 'type' => 'INSTANCE', 'name' => 'Table of Contents', 'x' => 0, 'y' => 3654, 'width' => 315, 'height' => 510, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 0.1, 'children' => array(
                                                array('id' => '4210:12595/4198:8360', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Table of Contents', 'width' => 180, 'height' => 24, 'fontSize' => 18),
                                                array('id' => '4210:12595/4188:11209', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Unboxing Experience', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4210:12595/4188:11211', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'The Build Process', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                                array('id' => '4210:12595/4188:11213', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Step-By-Step Construction', 'width' => 240, 'height' => 24, 'fontSize' => 16),
                                                array('id' => '4210:12595/4188:11220', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'Conclusion', 'width' => 240, 'height' => 28, 'fontSize' => 20),
                                            )),
                                        ),
                                    ),
                                ),
                            ),
                            array(
                                'id'       => 'sticky-multi:blog-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Blog Post Mobile',
                                'width'    => 390,
                                'height'   => 4200,
                                'children' => array(
                                    array('id' => 'sticky-multi:mobile-title', 'type' => 'TEXT', 'name' => 'Article title', 'characters' => 'Long article', 'width' => 320, 'height' => 44, 'fontSize' => 32),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page'     => true,
        'frame_ids'      => array('sticky-multi:home', 'sticky-multi:blog-desktop', 'sticky-multi:blog-mobile'),
        'entry_frame_id' => 'sticky-multi:home',
    ));
    $stickyGhostMultiPageHtml = $fileContent($stickyGhostMultiPageResult, 'blog-post.html');
    $stickyGhostMultiPageCss = $fileContent($stickyGhostMultiPageResult, 'style.css');
    $stickyGhostMultiPageLayout = $stickyGhostMultiPageResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert('' !== $stickyGhostMultiPageHtml, 'sticky-ghost-multi-page-blog-post-emitted');
    $assert(str_contains($stickyGhostMultiPageHtml, 'data-figma-node-id="4212:3087"'), 'sticky-ghost-multi-page-primary-emitted');
    $assert(! str_contains($stickyGhostMultiPageHtml, 'data-figma-node-id="4210:12595"'), 'sticky-ghost-multi-page-ghost-absent-from-final-html');
    $assert(str_contains($stickyGhostMultiPageCss, '.figma-node-4212-3087-table-of-contents{') && str_contains($stickyGhostMultiPageCss, 'position:sticky;top:0;align-self:flex-start'), 'sticky-ghost-multi-page-primary-sticky-in-final-css');
    $assert(! str_contains($stickyGhostMultiPageCss, '.figma-node-4210-12595-table-of-contents'), 'sticky-ghost-multi-page-ghost-absent-from-final-css');
    $assert(1 === ($stickyGhostMultiPageLayout['sticky_ghosts']['count'] ?? null), 'sticky-ghost-multi-page-diagnostic-count');
    $assert('4212:3087' === ($stickyGhostMultiPageLayout['sticky_ghosts']['candidates'][0]['primary_id'] ?? null), 'sticky-ghost-multi-page-diagnostic-primary');
    $assert('4210:12595' === ($stickyGhostMultiPageLayout['sticky_ghosts']['candidates'][0]['ghost_id'] ?? null), 'sticky-ghost-multi-page-diagnostic-ghost');

    $repeatedCardsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Repeated Cards Fixture',
        'nodes' => array(
            array(
                'id'       => 'repeat:root',
                'type'     => 'FRAME',
                'name'     => 'Repeated cards root',
                'width'    => 640,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'         => 'repeat:row',
                        'type'       => 'FRAME',
                        'name'       => 'Cards row',
                        'width'      => 640,
                        'height'     => 720,
                        'layoutMode' => 'HORIZONTAL',
                        'itemSpacing' => 24,
                        'children'   => array(
                            array('id' => 'repeat:card-a', 'type' => 'FRAME', 'name' => 'Promo card', 'width' => 240, 'height' => 180, 'children' => array(
                                array('id' => 'repeat:card-a-title', 'type' => 'TEXT', 'name' => 'Card title', 'characters' => 'Featured', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                            )),
                            array('id' => 'repeat:card-b', 'type' => 'FRAME', 'name' => 'Promo card', 'width' => 240, 'height' => 180, 'children' => array(
                                array('id' => 'repeat:card-b-title', 'type' => 'TEXT', 'name' => 'Card title', 'characters' => 'Featured', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                            )),
                            array('id' => 'repeat:card-c', 'type' => 'FRAME', 'name' => 'Promo card', 'x' => 0, 'y' => 540, 'width' => 240, 'height' => 180, 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'opacity' => 1, 'children' => array(
                                array('id' => 'repeat:card-c-title', 'type' => 'TEXT', 'name' => 'Card title', 'characters' => 'Featured', 'width' => 120, 'height' => 24, 'fontSize' => 18),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $repeatedCardsHtml = $fileContent($repeatedCardsResult, 'index.html');
    $repeatedCardsLayout = $repeatedCardsResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(str_contains($repeatedCardsHtml, 'data-figma-node-id="repeat:card-a"'), 'repeated-cards-flow-card-a-emitted');
    $assert(str_contains($repeatedCardsHtml, 'data-figma-node-id="repeat:card-b"'), 'repeated-cards-flow-card-b-emitted');
    $assert(str_contains($repeatedCardsHtml, 'data-figma-node-id="repeat:card-c"'), 'repeated-cards-full-opacity-absolute-emitted');
    $assert(0 === ($repeatedCardsLayout['sticky_ghosts']['count'] ?? null), 'repeated-cards-no-sticky-ghost-diagnostic');
    
    $emptyVisibleContainerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Empty Visible Container Classification Fixture',
        'nodes' => array(
            array(
                'id'       => 'empty-container:root',
                'type'     => 'FRAME',
                'name'     => 'Empty visible root',
                'width'    => 480,
                'height'   => 320,
                'children' => array(
                    array('id' => 'empty-container:separator', 'type' => 'FRAME', 'name' => '–', 'width' => 320, 'height' => 0.0002),
                    array('id' => 'empty-container:instance', 'type' => 'INSTANCE', 'name' => 'Missing component body', 'width' => 180, 'height' => 48),
                    array('id' => 'empty-container:frame', 'type' => 'FRAME', 'name' => 'Empty visual frame', 'width' => 24, 'height' => 24),
                    array('id' => 'empty-container:checkbox-row', 'type' => 'FRAME', 'name' => 'Checkbox row', 'width' => 240, 'height' => 24, 'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 8, 'children' => array(
                        array('id' => 'empty-container:checkbox', 'type' => 'FRAME', 'name' => 'Checkbox', 'width' => 24, 'height' => 24, 'strokeAlign' => 'INSIDE', 'strokeWeight' => 1, 'strokes' => array(array('type' => 'SOLID', 'color' => array('r' => 0.5, 'g' => 0.5, 'b' => 0.5, 'a' => 1)))),
                        array('id' => 'empty-container:checkbox-label', 'type' => 'TEXT', 'name' => 'Checkbox label', 'characters' => 'Save my details', 'width' => 120, 'height' => 21, 'fontSize' => 14),
                    )),
                ),
            ),
        ),
    ));
    $emptyVisibleLayout = $emptyVisibleContainerResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(4 === ($emptyVisibleLayout['empty_visible_container_count'] ?? null), 'empty-visible-container-classification-count');
    $assert(2 === ($emptyVisibleLayout['empty_visible_container_blocker_count'] ?? null), 'empty-visible-container-classification-blocker-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['decorative_zero_height_separator'] ?? null), 'empty-visible-container-classification-decorative-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['missing_instance_descendants'] ?? null), 'empty-visible-container-classification-instance-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['empty_visible_container'] ?? null), 'empty-visible-container-classification-frame-count');
    $assert(1 === ($emptyVisibleLayout['empty_visible_container_categories']['form_control_chrome'] ?? null), 'empty-visible-container-classification-form-control-count');
    $emptyVisibleById = array();
    foreach ( is_array($emptyVisibleLayout['empty_visible_containers'] ?? null) ? $emptyVisibleLayout['empty_visible_containers'] : array() as $sample ) {
        if ( is_array($sample) ) {
            $emptyVisibleById[(string) ($sample['node_id'] ?? '')] = $sample;
        }
    }
    $assert(false === ($emptyVisibleById['empty-container:separator']['blocks_parity'] ?? null), 'empty-visible-container-decorative-non-blocking');
    $assert(false === ($emptyVisibleById['empty-container:checkbox']['blocks_parity'] ?? null), 'empty-visible-container-form-control-non-blocking');
    $assert('form_control_chrome' === ($emptyVisibleById['empty-container:checkbox']['category'] ?? null), 'empty-visible-container-form-control-category');
    $assert(true === ($emptyVisibleById['empty-container:instance']['blocks_parity'] ?? null), 'empty-visible-container-instance-blocking');
    $assert('missing_instance_descendants' === ($emptyVisibleById['empty-container:instance']['category'] ?? null), 'empty-visible-container-instance-category');
    
    $multiPageEmptyVisibleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multi Page Empty Visible Classification Fixture',
        'nodes' => array(
            array(
                'id'       => 'multi-empty:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Pages',
                'children' => array(
                    array('id' => 'multi-empty:one', 'type' => 'FRAME', 'name' => 'One', 'width' => 320, 'height' => 240, 'children' => array(
                        array('id' => 'multi-empty:one-separator', 'type' => 'FRAME', 'name' => '–', 'width' => 280, 'height' => 0.0002),
                    )),
                    array('id' => 'multi-empty:two', 'type' => 'FRAME', 'name' => 'Two', 'width' => 320, 'height' => 240, 'children' => array(
                        array('id' => 'multi-empty:two-instance', 'type' => 'INSTANCE', 'name' => 'Missing component body', 'width' => 120, 'height' => 32),
                    )),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('multi-empty:one', 'multi-empty:two'), 'entry_frame_id' => 'multi-empty:one'));
    $multiPageEmptyVisibleLayout = $multiPageEmptyVisibleResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(2 === ($multiPageEmptyVisibleLayout['empty_visible_container_count'] ?? null), 'empty-visible-container-multi-page-count');
    $assert(1 === ($multiPageEmptyVisibleLayout['empty_visible_container_blocker_count'] ?? null), 'empty-visible-container-multi-page-blocker-count');
    $assert(1 === ($multiPageEmptyVisibleLayout['empty_visible_container_categories']['decorative_zero_height_separator'] ?? null), 'empty-visible-container-multi-page-decorative-count');
    $assert(1 === ($multiPageEmptyVisibleLayout['empty_visible_container_categories']['missing_instance_descendants'] ?? null), 'empty-visible-container-multi-page-instance-count');
    
    $multiPageOffCanvasResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multi Page Off Canvas Diagnostics Fixture',
        'nodes' => array(
            array(
                'id'       => 'multi-offset:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Site pages',
                'children' => array(
                    array(
                        'id'       => 'multi-offset:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 400,
                        'height'   => 300,
                        'children' => array(
                            array('id' => 'multi-offset:home-title', 'type' => 'TEXT', 'name' => 'Home title', 'characters' => 'Home', 'fontSize' => 20),
                        ),
                    ),
                    array(
                        'id'       => 'multi-offset:about',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 400,
                        'height'   => 300,
                        'children' => array(
                            array('id' => 'multi-offset:hero', 'type' => 'FRAME', 'name' => 'Drifted hero', 'x' => 1200, 'y' => 0, 'width' => 120, 'height' => 80, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(
                                array('id' => 'multi-offset:hero-copy', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'About', 'fontSize' => 18),
                            )),
                        ),
                    ),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('multi-offset:home', 'multi-offset:about'), 'entry_frame_id' => 'multi-offset:home'));
    $multiPageOffCanvasDiagnostics = $multiPageOffCanvasResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $multiPageOffCanvasSignal = null;
    foreach ( is_array($multiPageOffCanvasDiagnostics['artifact_quality']['signals'] ?? null) ? $multiPageOffCanvasDiagnostics['artifact_quality']['signals'] : array() as $signal ) {
        if ( is_array($signal) && 'off_canvas_visual_nodes' === ($signal['code'] ?? null) ) {
            $multiPageOffCanvasSignal = $signal;
            break;
        }
    }
    $assert('multi_page' === ($multiPageOffCanvasDiagnostics['scope'] ?? null), 'quality-diagnostics-multi-page-off-canvas-scope');
    $assert(1 === ($multiPageOffCanvasDiagnostics['layout']['off_canvas_visual_node_count'] ?? null), 'quality-diagnostics-multi-page-off-canvas-count');
    $assert('multi-offset:hero' === ($multiPageOffCanvasSignal['sample_nodes'][0]['node_id'] ?? null), 'quality-diagnostics-multi-page-off-canvas-sample-node');
    $assert('about.html' === ($multiPageOffCanvasSignal['sample_nodes'][0]['page_path'] ?? null), 'quality-diagnostics-multi-page-off-canvas-sample-page');
    
    $cleanQualityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Clean Quality Fixture',
        'nodes' => array(
            array(
                'id'       => 'clean:root',
                'type'     => 'FRAME',
                'name'     => 'Simple content',
                'width'    => 720,
                'height'   => 320,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array('id' => 'clean:title', 'type' => 'TEXT', 'name' => 'Page title', 'characters' => 'Clean page', 'fontSize' => 24),
                    array('id' => 'clean:copy', 'type' => 'TEXT', 'name' => 'Page copy', 'characters' => 'A simple responsive-friendly page.', 'fontSize' => 16),
                ),
            ),
        ),
    ));
    $assert(array() === $artifactQualitySignalCodes($cleanQualityResult), 'quality-diagnostics-clean-page-no-signals');
    $assert('clean' === ($cleanQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['status'] ?? null), 'quality-diagnostics-clean-page-status');
    $assert('pass' === ($cleanQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['quality_status'] ?? null), 'quality-diagnostics-clean-page-quality-status-pass');
    
    $incompleteQualityResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Incomplete Quality Fixture',
        'nodes' => array(
            array(
                'id'       => 'incomplete:root',
                'type'     => 'FRAME',
                'name'     => 'Incomplete page',
                'width'    => 720,
                'height'   => 320,
                'children' => array(
                    array('id' => 'incomplete:image', 'type' => 'RECTANGLE', 'name' => 'Missing hero asset', 'width' => 320, 'height' => 180, 'asset_id' => 'missing-hero'),
                    array('id' => 'incomplete:vector', 'type' => 'VECTOR', 'name' => 'Unsupported logo mark'),
                ),
            ),
        ),
    ));
    $incompleteQuality = $incompleteQualityResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality'] ?? array();
    $assert('fail' === ($incompleteQuality['quality_status'] ?? null), 'quality-diagnostics-incomplete-quality-status-fail');
    $assert(1 === ($incompleteQuality['summary']['missing_asset_nodes'] ?? null), 'quality-diagnostics-incomplete-missing-asset-count');
    $assert(1 === ($incompleteQuality['summary']['vector_placeholders'] ?? null), 'quality-diagnostics-incomplete-vector-placeholder-count');
    
    $offsetPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'     => 'Offset Board Fixture',
        'document' => array(
            'id'       => 'doc:1',
            'type'     => 'DOCUMENT',
            'name'     => 'Document',
            'children' => array(
                array(
                    'id'       => 'canvas:1',
                    'type'     => 'CANVAS',
                    'name'     => 'Page 1',
                    'children' => array(
                        array(
                            'id'                  => 'frame:selected',
                            'type'                => 'FRAME',
                            'name'                => 'Selected Website Page',
                            'absoluteBoundingBox' => array('x' => 3497, 'y' => 212, 'width' => 1440, 'height' => 900),
                            'children'            => array(
                                array(
                                    'id'                  => 'frame:selected-card',
                                    'type'                => 'FRAME',
                                    'name'                => 'Hero Card',
                                    'absoluteBoundingBox' => array('x' => 3537, 'y' => 252, 'width' => 320, 'height' => 160),
                                    'layoutPositioning'   => 'ABSOLUTE',
                                    'children'            => array(
                                        array(
                                            'id'                  => 'text:selected-title',
                                            'type'                => 'TEXT',
                                            'name'                => 'Hero title',
                                            'text'                => 'Selected page content',
                                            'fontSize'            => 32,
                                            'absoluteBoundingBox' => array('x' => 3561, 'y' => 280, 'width' => 220, 'height' => 44),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                        array(
                            'id'                  => 'frame:off-canvas-one',
                            'type'                => 'FRAME',
                            'name'                => 'Off Canvas One',
                            'absoluteBoundingBox' => array('x' => 4680, 'y' => 212, 'width' => 1440, 'height' => 900),
                        ),
                        array(
                            'id'                  => 'frame:off-canvas-two',
                            'type'                => 'FRAME',
                            'name'                => 'Off Canvas Two',
                            'absoluteBoundingBox' => array('x' => -2200, 'y' => 212, 'width' => 1440, 'height' => 900),
                        ),
                    ),
                ),
            ),
        ),
    ), array('frame_id' => 'frame:selected'));
    $offsetPageHtml = $fileContent($offsetPageResult, 'index.html');
    $offsetPageCss = $fileContent($offsetPageResult, 'style.css');
    $assert('success' === ($offsetPageResult['status'] ?? null), 'offset-page-transform-success');
    $assert(str_contains($offsetPageHtml, 'Selected page content'), 'offset-page-selected-content-rendered');
    $assert(! str_contains($offsetPageHtml, 'Off Canvas One') && ! str_contains($offsetPageHtml, 'Off Canvas Two'), 'offset-page-off-canvas-siblings-omitted');
    $assert(str_contains($offsetPageCss, '.figma-root{position:relative;width:100%}'), 'offset-page-root-shell-is-fluid');
    $assert(str_contains($offsetPageCss, '.figma-node-frame-selected-selected-website-page{width:100%;height:900px;position:relative}'), 'offset-page-root-renders-fluid-full-bleed');
    $assert(str_contains($offsetPageCss, '.figma-node-frame-selected-card-hero-card{width:320px;height:160px;position:absolute;left:40px;top:40px}'), 'offset-page-child-rebased-position');
    $assert(! str_contains($offsetPageCss, 'left:3497px') && ! str_contains($offsetPageCss, 'left:3537px') && ! str_contains($offsetPageCss, 'left:4680px'), 'offset-page-avoids-board-left-values');
}

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_site_generation_planning_contract(callable $assert, callable $fileContent, string $externalizedVectorPath): void
{
    $frameInspection = blocks_engine_figma_transformer_inspect_frames_scenegraph(array(
        'nodes' => array(
            array(
                'id' => 'page:1',
                'type' => 'CANVAS',
                'name' => 'Page One',
                'children' => array(
                    array(
                        'id' => 'section:1',
                        'type' => 'SECTION',
                        'name' => 'Marketing Pages',
                        'width' => 2000,
                        'height' => 1600,
                        'children' => array(
                            array(
                                'id' => 'frame:home',
                                'type' => 'FRAME',
                                'name' => 'Home Page',
                                'width' => 1440,
                                'height' => 1200,
                                'children' => array(
                                    array('id' => 'text:hero', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Hello'),
                                    array('id' => 'image:hero', 'type' => 'RECTANGLE', 'name' => 'Hero image', 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'hero'))),
                                ),
                            ),
                            array(
                                'id' => 'frame:home-mobile',
                                'type' => 'FRAME',
                                'name' => 'Home Page Mobile',
                                'width' => 390,
                                'height' => 1200,
                                'children' => array(
                                    array('id' => 'text:hero-mobile', 'type' => 'TEXT', 'name' => 'Hero Mobile', 'characters' => 'Hello'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array('frame_inspection_limit' => 3));
    $frameInspectionHome = null;
    foreach ( $frameInspection['candidates'] ?? array() as $candidate ) {
        if ( is_array($candidate) && 'frame:home' === ($candidate['id'] ?? null) ) {
            $frameInspectionHome = $candidate;
            break;
        }
    }
    $assert('blocks-engine/figma-transformer/frame-inspection/v1' === ($frameInspection['schema'] ?? null), 'frame-inspection-schema');
    $assert(3 === ($frameInspection['returned_count'] ?? null), 'frame-inspection-limit');
    $assert('Page One' === ($frameInspectionHome['page']['name'] ?? null), 'frame-inspection-page-ancestor');
    $assert('Marketing Pages' === ($frameInspectionHome['section']['name'] ?? null), 'frame-inspection-section-ancestor');
    $assert(1 === ($frameInspectionHome['text_count'] ?? null), 'frame-inspection-text-count');
    $assert(1 === ($frameInspectionHome['asset_reference_count'] ?? null), 'frame-inspection-asset-count');
    $assert('desktop' === ($frameInspectionHome['device_hint'] ?? null), 'frame-inspection-desktop-device-hint');
    $assert('frame:home-mobile' === ($frameInspectionHome['responsive_siblings'][0]['id'] ?? null), 'frame-inspection-responsive-sibling-id');
    $assert('mobile' === ($frameInspectionHome['responsive_siblings'][0]['device_hint'] ?? null), 'frame-inspection-responsive-sibling-device-hint');
    
    $responsivePagePlanSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:responsive',
                'type'     => 'CANVAS',
                'name'     => 'Responsive Pages',
                'children' => array(
                    array(
                        'id'       => 'section:responsive',
                        'type'     => 'SECTION',
                        'name'     => 'Marketing',
                        'width'    => 4000,
                        'height'   => 5000,
                        'children' => array(
                            array(
                                'id'       => 'frame:home-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Home Page Desktop',
                                'width'    => 1440,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                                ),
                            ),
                            array(
                                'id'       => 'frame:home-tablet',
                                'type'     => 'FRAME',
                                'name'     => 'Home Page Tablet',
                                'width'    => 834,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'text:home-tablet', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                                ),
                            ),
                            array(
                                'id'       => 'frame:home-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Home Page Mobile',
                                'width'    => 390,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Hero', 'characters' => 'Welcome'),
                                ),
                            ),
                            array(
                                'id'       => 'frame:about-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'About Page',
                                'width'    => 1440,
                                'height'   => 3000,
                                'children' => array(
                                    array('id' => 'text:about', 'type' => 'TEXT', 'name' => 'About', 'characters' => 'About us'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $responsivePagePlan = ( new ScenegraphPagePlanner() )->plan($responsivePagePlanSource, array('include_all_pages' => true));
    $responsivePageByFrame = static function (array $plan, string $frameId): ?array {
        foreach ( $plan['pages'] ?? array() as $page ) {
            if ( is_array($page) && $frameId === ($page['frame_id'] ?? null) ) {
                return $page;
            }
        }
    
        return null;
    };
    $responsiveHomePage = $responsivePageByFrame($responsivePagePlan, 'frame:home-desktop');
    $responsiveAboutPage = $responsivePageByFrame($responsivePagePlan, 'frame:about-desktop');
    $assert(4 === ($responsivePagePlan['candidate_count'] ?? null), 'page-plan-responsive-candidate-count');
    $assert(2 === ($responsivePagePlan['page_count'] ?? null), 'page-plan-responsive-collapses-to-two-pages');
    $assert(null !== $responsiveHomePage, 'page-plan-responsive-home-primary-is-desktop');
    $assert(true === ($responsiveHomePage['responsive'] ?? null), 'page-plan-responsive-home-flagged-responsive');
    $assert(3 === ($responsiveHomePage['breakpoint_count'] ?? null), 'page-plan-responsive-home-three-breakpoints');
    // A responsive page's slug reflects the PAGE, not its widest variant: the
    // breakpoint token ("Desktop") is stripped so desktop+mobile collapse to one
    // stable slug.
    $assert('home-page' === ($responsiveHomePage['slug'] ?? null), 'page-plan-responsive-slug-strips-breakpoint-token');
    $assert(array('frame:home-desktop', 'frame:home-tablet', 'frame:home-mobile')
        === array_map(static fn (array $variant): string => (string) ($variant['frame_id'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variants-ordered-widest-first');
    $assert(array('desktop', 'tablet', 'mobile')
        === array_map(static fn (array $variant): string => (string) ($variant['device_hint'] ?? ''), $responsiveHomePage['variants'] ?? array()), 'page-plan-responsive-variant-device-hints');
    $assert(true === ($responsiveHomePage['variants'][0]['primary'] ?? null)
        && false === ($responsiveHomePage['variants'][1]['primary'] ?? null)
        && false === ($responsiveHomePage['variants'][2]['primary'] ?? null), 'page-plan-responsive-only-widest-is-primary');
    $assert(1440.0 === ($responsiveHomePage['variants'][0]['viewport_width'] ?? null)
        && 390.0 === ($responsiveHomePage['variants'][2]['viewport_width'] ?? null), 'page-plan-responsive-variant-viewport-widths');
    $assert(null !== $responsiveAboutPage, 'page-plan-responsive-about-stays-its-own-page');
    $assert(false === ($responsiveAboutPage['responsive'] ?? null) && 1 === ($responsiveAboutPage['breakpoint_count'] ?? null), 'page-plan-non-responsive-frame-single-variant');
    $assert(1 === count($responsiveAboutPage['variants'] ?? array()) && 'frame:about-desktop' === ($responsiveAboutPage['variants'][0]['frame_id'] ?? null), 'page-plan-non-responsive-frame-self-variant');
    
    // RESPONSIVE EMISSION (#247 item 2): StaticHtmlEmitter::emitSite consumes a
    // page plan's `variants[]` and renders the primary (widest) variant as the base
    // layout, then emits `@media (max-width: …)` blocks carrying ONLY the per-node
    // style declarations that differ at each narrower breakpoint. A single-variant
    // page emits no `@media` at all. Variant frames are matched onto the base frame
    // by structural position so the overrides key on the base class names.
    $responsiveEmitFrame = static function (string $id, string $name, float $width, float $height, array $children): array {
        return array(
            'id'         => $id,
            'type'       => 'FRAME',
            'name'       => $name,
            'box'        => array('width' => $width, 'height' => $height),
            'background' => '#ffffff',
            'children'   => $children,
        );
    };
    $responsiveEmitCard = static function (string $id, string $name, float $width, float $height, string $background): array {
        return array(
            'id'         => $id,
            'type'       => 'RECTANGLE',
            'name'       => $name,
            'box'        => array('width' => $width, 'height' => $height),
            'background' => $background,
        );
    };
    $responsiveEmitScenegraph = array(
        'name'  => 'Responsive Emission Site',
        'nodes' => array(
            $responsiveEmitFrame('frame:home-desktop', 'Home Desktop', 1440.0, 3000.0, array(
                $responsiveEmitCard('card:desktop', 'Hero Card', 1200.0, 400.0, '#ff0000'),
            )),
            $responsiveEmitFrame('frame:home-tablet', 'Home Tablet', 834.0, 3000.0, array(
                $responsiveEmitCard('card:tablet', 'Hero Card', 700.0, 400.0, '#ff0000'),
            )),
            $responsiveEmitFrame('frame:home-mobile', 'Home Mobile', 390.0, 3200.0, array(
                $responsiveEmitCard('card:mobile', 'Hero Card', 350.0, 500.0, '#00ff00'),
            )),
            $responsiveEmitFrame('frame:about', 'About', 1440.0, 2000.0, array(
                $responsiveEmitCard('card:about', 'About Card', 1100.0, 300.0, '#0000ff'),
            )),
        ),
    );
    $responsiveEmitPagePlan = array(
        'pages' => array(
            array(
                'frame_id'         => 'frame:home-desktop',
                'name'             => 'Home',
                'path'             => 'index.html',
                'entrypoint'       => true,
                'responsive'       => true,
                'breakpoint_count' => 3,
                'variants'         => array(
                    array('frame_id' => 'frame:home-desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                    array('frame_id' => 'frame:home-tablet', 'device_hint' => 'tablet', 'viewport_width' => 834.0, 'primary' => false, 'order' => 1),
                    array('frame_id' => 'frame:home-mobile', 'device_hint' => 'mobile', 'viewport_width' => 390.0, 'primary' => false, 'order' => 2),
                ),
            ),
            array(
                'frame_id'         => 'frame:about',
                'name'             => 'About',
                'path'             => 'about.html',
                'entrypoint'       => false,
                'responsive'       => false,
                'breakpoint_count' => 1,
                'variants'         => array(
                    array('frame_id' => 'frame:about', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                ),
            ),
        ),
    );
    $responsiveEmitResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveEmitScenegraph, $responsiveEmitPagePlan);
    $responsiveEmitCss = '';
    foreach ( $responsiveEmitResult['files'] ?? array() as $responsiveEmitFile ) {
        if ( is_array($responsiveEmitFile) && 'style.css' === ($responsiveEmitFile['path'] ?? null) ) {
            $responsiveEmitCss = (string) ($responsiveEmitFile['content'] ?? '');
        }
    }
    $assert('success' === ($responsiveEmitResult['status'] ?? null), 'responsive-emit-status-success');
    $assert('' !== $responsiveEmitCss, 'responsive-emit-stylesheet-present');
    // Two narrower breakpoints => exactly two media blocks, keyed at the MIDPOINT
    // between adjacent variant widths (not the narrow variant's own width).
    // desktop=1440, tablet=834, mobile=390:
    //   tablet breakpoint = round((1440+834)/2) = 1137
    //   mobile breakpoint = round((834+390)/2)  = 612
    $assert(2 === substr_count($responsiveEmitCss, '@media'), 'responsive-emit-two-media-blocks');
    $assert(str_contains($responsiveEmitCss, '@media (max-width:1137px){'), 'responsive-emit-tablet-media-query');
    $assert(str_contains($responsiveEmitCss, '@media (max-width:612px){'), 'responsive-emit-mobile-media-query');
    // Base layout uses the primary (desktop) variant styles, emitted before media.
    $responsiveEmitBasePos = strpos($responsiveEmitCss, '.figma-node-card-desktop-hero-card{width:1200px;height:400px;background:#ff0000}');
    $assert(false !== $responsiveEmitBasePos, 'responsive-emit-base-uses-primary-variant');
    $responsiveEmitFirstMediaPos = strpos($responsiveEmitCss, '@media');
    $assert(false !== $responsiveEmitFirstMediaPos && $responsiveEmitBasePos < $responsiveEmitFirstMediaPos, 'responsive-emit-base-precedes-media');
    // Narrower-wins cascade: tablet block precedes mobile block.
    $assert(strpos($responsiveEmitCss, '@media (max-width:1137px)') < strpos($responsiveEmitCss, '@media (max-width:612px)'), 'responsive-emit-cascade-widest-first');
    // Media blocks override on the BASE class names, carrying only changed props.
    $responsiveEmitTabletBlock = substr($responsiveEmitCss, strpos($responsiveEmitCss, '@media (max-width:1137px)'), strpos($responsiveEmitCss, '@media (max-width:612px)') - strpos($responsiveEmitCss, '@media (max-width:1137px)'));
    $assert(str_contains($responsiveEmitTabletBlock, '.figma-node-card-desktop-hero-card{width:700px}'), 'responsive-emit-tablet-card-width-diff-only');
    $assert(! str_contains($responsiveEmitTabletBlock, 'background:'), 'responsive-emit-tablet-omits-unchanged-background');
    $responsiveEmitMobileBlock = substr($responsiveEmitCss, strpos($responsiveEmitCss, '@media (max-width:612px)'));
    $assert(str_contains($responsiveEmitMobileBlock, '.figma-node-card-desktop-hero-card{width:350px;height:500px;background:#00ff00}'), 'responsive-emit-mobile-card-diffs-width-height-background');
    // The single-variant About page contributes NO media override for its nodes.
    $assert(0 === preg_match('/@media[^@]*figma-node-card-about/s', $responsiveEmitCss), 'responsive-emit-single-variant-page-no-media');
    
    // SINGLE-VARIANT PAGE PARITY: a page plan with only primary variants emits the
    // SAME CSS as today — zero `@media` queries.
    $singleVariantPagePlan = array(
        'pages' => array(
            array(
                'frame_id'         => 'frame:about',
                'name'             => 'About',
                'path'             => 'index.html',
                'entrypoint'       => true,
                'responsive'       => false,
                'breakpoint_count' => 1,
                'variants'         => array(
                    array('frame_id' => 'frame:about', 'device_hint' => 'desktop', 'viewport_width' => 1440.0, 'primary' => true, 'order' => 0),
                ),
            ),
        ),
    );
    $singleVariantResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite($responsiveEmitScenegraph, $singleVariantPagePlan);
    $singleVariantCss = '';
    foreach ( $singleVariantResult['files'] ?? array() as $singleVariantFile ) {
        if ( is_array($singleVariantFile) && 'style.css' === ($singleVariantFile['path'] ?? null) ) {
            $singleVariantCss = (string) ($singleVariantFile['content'] ?? '');
        }
    }
    $assert('' !== $singleVariantCss, 'responsive-emit-single-variant-stylesheet-present');
    $assert(! str_contains($singleVariantCss, '@media'), 'responsive-emit-single-variant-no-media-query');
    
    $planDiagnosticByCode = static function (array $plan, string $code): ?array {
        foreach ( $plan['diagnostics'] ?? array() as $diagnostic ) {
            if ( is_array($diagnostic) && $code === ($diagnostic['code'] ?? null) ) {
                return $diagnostic;
            }
        }
    
        return null;
    };
    
    // (b) A genuine desktop/tablet/mobile group STILL collapses AND records its
    // grouping rationale (distinct device hints).
    $responsiveGroupDiagnostic = $planDiagnosticByCode($responsivePagePlan, 'responsive_group_formed');
    $assert(null !== $responsiveGroupDiagnostic, 'page-plan-responsive-group-rationale-emitted');
    $assert(in_array('device_hint_diversity', $responsiveGroupDiagnostic['reasons'] ?? array(), true), 'page-plan-responsive-group-rationale-device-diversity');
    $assert('frame:home-desktop' === ($responsiveGroupDiagnostic['primary_frame_id'] ?? null), 'page-plan-responsive-group-rationale-primary');
    
    // (a) FALSE-POSITIVE GUARD: four same-name, same-device-hint (desktop),
    // same-width (1440) frames differing only in height are duplicate/iteration
    // drafts (the real "For Hosts" data finding), NOT responsive breakpoints. They
    // must stay as separate pages and surface a duplicate_draft_frames diagnostic.
    $duplicateDraftSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:hosts',
                'type'     => 'CANVAS',
                'name'     => 'Hosts Pages',
                'children' => array(
                    array(
                        'id'       => 'section:hosts',
                        'type'     => 'SECTION',
                        'name'     => 'Marketing',
                        'width'    => 4000,
                        'height'   => 40000,
                        'children' => array(
                            array(
                                'id'       => 'frame:hosts-a',
                                'type'     => 'FRAME',
                                'name'     => 'For Hosts',
                                'width'    => 1440,
                                'height'   => 9777,
                                'children' => array(array('id' => 'text:hosts-a', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                            ),
                            array(
                                'id'       => 'frame:hosts-b',
                                'type'     => 'FRAME',
                                'name'     => 'For Hosts',
                                'width'    => 1440,
                                'height'   => 8613,
                                'children' => array(array('id' => 'text:hosts-b', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                            ),
                            array(
                                'id'       => 'frame:hosts-c',
                                'type'     => 'FRAME',
                                'name'     => 'For Hosts',
                                'width'    => 1440,
                                'height'   => 9188,
                                'children' => array(array('id' => 'text:hosts-c', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                            ),
                            array(
                                'id'       => 'frame:hosts-d',
                                'type'     => 'FRAME',
                                'name'     => 'For Hosts',
                                'width'    => 1440,
                                'height'   => 9000,
                                'children' => array(array('id' => 'text:hosts-d', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'For Hosts')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $duplicateDraftPlan = ( new ScenegraphPagePlanner() )->plan($duplicateDraftSource, array('include_all_pages' => true));
    $assert(4 === ($duplicateDraftPlan['page_count'] ?? null), 'page-plan-duplicate-drafts-stay-separate-pages');
    $duplicateDraftResponsive = false;
    foreach ( $duplicateDraftPlan['pages'] ?? array() as $duplicatePage ) {
        if ( is_array($duplicatePage) && true === ($duplicatePage['responsive'] ?? null) ) {
            $duplicateDraftResponsive = true;
        }
    }
    $assert(false === $duplicateDraftResponsive, 'page-plan-duplicate-drafts-not-responsive');
    $duplicateDraftDiagnostic = $planDiagnosticByCode($duplicateDraftPlan, 'duplicate_draft_frames');
    $assert(null !== $duplicateDraftDiagnostic, 'page-plan-duplicate-drafts-diagnostic-emitted');
    $assert('desktop' === ($duplicateDraftDiagnostic['device_hint'] ?? null), 'page-plan-duplicate-drafts-diagnostic-device-hint');
    $assert(4 === count($duplicateDraftDiagnostic['frame_ids'] ?? array()), 'page-plan-duplicate-drafts-diagnostic-frame-count');
    $assert(null === $planDiagnosticByCode($duplicateDraftPlan, 'responsive_group_formed'), 'page-plan-duplicate-drafts-not-grouped');
    
    // (c) FRAME-CANDIDATE BOUND: detection now scales with the number of FRAME
    // candidates, not total node count. The `responsive_detection_bounded`
    // diagnostic only fires in pathological cases (here forced via a frame-limit of
    // 1 against 4 frames). When bounded, detection is skipped — no second index is
    // built — and frames fall back to one-page-per-frame.
    $boundedPlan = ( new ScenegraphPagePlanner() )->plan(
        $responsivePagePlanSource,
        array('include_all_pages' => true, 'responsive_detection_frame_limit' => 1)
    );
    $boundedDiagnostic = $planDiagnosticByCode($boundedPlan, 'responsive_detection_bounded');
    $assert(null !== $boundedDiagnostic, 'page-plan-bounded-detection-diagnostic-emitted');
    $assert(1 === ($boundedDiagnostic['frame_candidate_limit'] ?? null), 'page-plan-bounded-detection-frame-limit');
    $assert(4 === ($boundedDiagnostic['frame_candidate_count'] ?? null), 'page-plan-bounded-detection-frame-count');
    $assert(4 === ($boundedPlan['page_count'] ?? null), 'page-plan-bounded-detection-one-page-per-frame');
    $boundedHomePage = $responsivePageByFrame($boundedPlan, 'frame:home-desktop');
    $assert(null !== $boundedHomePage && false === ($boundedHomePage['responsive'] ?? null), 'page-plan-bounded-detection-no-collapse');
    
    // (c2) SCALE: a design whose descendant node count is WELL ABOVE the old 25k
    // ceiling — but with only a handful of FRAME candidates — must STILL run
    // detection and form a genuine desktop/tablet/mobile responsive group, with NO
    // `responsive_detection_bounded` skip. This proves grouping stays ON at
    // "Automattic scale" and that detection reads frame-level data, not all nodes.
    $largeDescendantCount = 26000; // > the retired RESPONSIVE_DETECTION_NODE_LIMIT of 25000.
    $bulkChildren = static function (string $prefix, int $count): array {
        $children = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $children[] = array(
                'id'         => $prefix . ':text:' . $i,
                'type'       => 'TEXT',
                'name'       => 'Body ' . $i,
                'characters' => 'Lorem ipsum dolor sit amet number ' . $i,
            );
        }
    
        return $children;
    };
    $scaleSource = array(
        'nodes' => array(
            array(
                'id'       => 'page:scale',
                'type'     => 'CANVAS',
                'name'     => 'Scale Pages',
                'children' => array(
                    array(
                        'id'       => 'section:scale',
                        'type'     => 'SECTION',
                        'name'     => 'Marketing',
                        'width'    => 4000,
                        'height'   => 40000,
                        'children' => array(
                            array(
                                'id'       => 'frame:landing-desktop',
                                'type'     => 'FRAME',
                                'name'     => 'Landing Page Desktop',
                                'width'    => 1440,
                                'height'   => 9000,
                                // This single frame alone carries more descendants
                                // than the entire old 25k node ceiling.
                                'children' => $bulkChildren('desktop', $largeDescendantCount),
                            ),
                            array(
                                'id'       => 'frame:landing-tablet',
                                'type'     => 'FRAME',
                                'name'     => 'Landing Page Tablet',
                                'width'    => 834,
                                'height'   => 9000,
                                'children' => array(array('id' => 'tablet:text', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Lorem')),
                            ),
                            array(
                                'id'       => 'frame:landing-mobile',
                                'type'     => 'FRAME',
                                'name'     => 'Landing Page Mobile',
                                'width'    => 390,
                                'height'   => 9000,
                                'children' => array(array('id' => 'mobile:text', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Lorem')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
    $scalePlan = ( new ScenegraphPagePlanner() )->plan($scaleSource, array('include_all_pages' => true));
    $assert(null === $planDiagnosticByCode($scalePlan, 'responsive_detection_bounded'), 'page-plan-scale-detection-not-bounded');
    $assert(3 === ($scalePlan['candidate_count'] ?? null), 'page-plan-scale-three-frame-candidates');
    $assert(1 === ($scalePlan['page_count'] ?? null), 'page-plan-scale-collapses-to-one-page');
    $scaleHomePage = $responsivePageByFrame($scalePlan, 'frame:landing-desktop');
    $assert(null !== $scaleHomePage, 'page-plan-scale-primary-is-desktop');
    $assert(true === ($scaleHomePage['responsive'] ?? null), 'page-plan-scale-flagged-responsive');
    $assert(3 === ($scaleHomePage['breakpoint_count'] ?? null), 'page-plan-scale-three-breakpoints');
    // The primary frame's own subtree exceeds the retired node ceiling, proving
    // grouping is active at a scale that previously auto-disabled it.
    $assert(($scaleHomePage['node_count'] ?? 0) > 25000, 'page-plan-scale-primary-above-old-ceiling');
    $scaleGroupDiagnostic = $planDiagnosticByCode($scalePlan, 'responsive_group_formed');
    $assert(null !== $scaleGroupDiagnostic, 'page-plan-scale-group-formed');
    $assert(in_array('device_hint_diversity', $scaleGroupDiagnostic['reasons'] ?? array(), true), 'page-plan-scale-group-device-diversity');
    $assert(array('desktop', 'tablet', 'mobile')
        === array_map(static fn (array $variant): string => (string) ($variant['device_hint'] ?? ''), $scaleHomePage['variants'] ?? array()), 'page-plan-scale-variant-device-hints');
    
    // (c3) DETECTION IS FRAME-LEVEL: the lightweight detection path produces the
    // full detection contract (device_hint / sibling_group_key /
    // responsive_siblings) from frame-level records ALONE — no source, no
    // ScenegraphIndex. This is the memory-efficient primitive the planner reuses.
    $frameLevelDetection = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphFrameInspector() )->detectResponsiveFrames(array(
        array('id' => 'f:desktop', 'name' => 'Pricing Desktop', 'width' => 1440.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
        array('id' => 'f:tablet', 'name' => 'Pricing Tablet', 'width' => 834.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
        array('id' => 'f:mobile', 'name' => 'Pricing Mobile', 'width' => 390.0, 'height' => 3200.0, 'page_id' => 'p:1', 'section_id' => 's:1', 'parent_id' => 's:1'),
    ));
    $assert('desktop' === ($frameLevelDetection['f:desktop']['device_hint'] ?? null), 'frame-level-detection-desktop-hint');
    $assert('mobile' === ($frameLevelDetection['f:mobile']['device_hint'] ?? null), 'frame-level-detection-mobile-hint');
    $assert(isset($frameLevelDetection['f:desktop']['sibling_group_key']), 'frame-level-detection-sibling-group-key');
    $frameLevelSiblingIds = array_map(
        static fn (array $sibling): string => (string) ($sibling['id'] ?? ''),
        $frameLevelDetection['f:desktop']['responsive_siblings'] ?? array()
    );
    $assert(in_array('f:tablet', $frameLevelSiblingIds, true) && in_array('f:mobile', $frameLevelSiblingIds, true), 'frame-level-detection-links-siblings');
    
    $matrixWebsiteCandidate = array(
        'id'         => 'matrix:site:home',
        'type'       => 'FRAME',
        'name'       => 'Homepage',
        'page'       => array('name' => 'Website Pages'),
        'parent'     => array('type' => 'CANVAS'),
        'width'      => 1440,
        'height'     => 4200,
        'text_count' => 24,
        'score'      => 700,
    );
    $matrixResearchCandidate = array(
        'id'         => 'matrix:research:screen',
        'type'       => 'FRAME',
        'name'       => 'Desktop - 12',
        'page'       => array('name' => 'Research/Screens'),
        'parent'     => array('type' => 'CANVAS'),
        'width'      => 1440,
        'height'     => 4600,
        'text_count' => 40,
        'score'      => 820,
    );
    $matrixSelection = matrix_select_frame_ids(array('candidates' => array($matrixResearchCandidate, $matrixWebsiteCandidate)), 2);
    $assert(array('matrix:site:home', 'matrix:research:screen') === $matrixSelection, 'fixture-matrix-research-screens-demoted');
    $assert(matrix_candidate_rank($matrixWebsiteCandidate) > matrix_candidate_rank($matrixResearchCandidate), 'fixture-matrix-reference-page-rank-penalty');
    $assert(! in_array('page_collection', matrix_candidate_selection_reasons($matrixResearchCandidate), true), 'fixture-matrix-reference-page-not-page-collection');
    
    $multiPageResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Multi Page Fixture',
        'assets' => array(
            'home-image' => array('mime_type' => 'image/png', 'content' => 'home image'),
            'about-image' => array('mime_type' => 'image/png', 'content' => 'about image'),
            'unused-image' => array('mime_type' => 'image/png', 'content' => 'unused image'),
        ),
        'nodes'  => array(
            array(
                'id'       => 'page:multi',
                'type'     => 'CANVAS',
                'name'     => 'Site Pages',
                'children' => array(
                    array(
                        'id'       => 'frame:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home',
                        'width'    => 1440,
                        'height'   => 1200,
                        'children' => array(
                            array('id' => 'text:home', 'type' => 'TEXT', 'name' => 'Home title', 'characters' => 'Home Hero', 'fontName' => array('family' => 'Example Sans', 'style' => 'Regular'), 'fontSize' => 20),
                            array('id' => 'image:home', 'type' => 'RECTANGLE', 'name' => 'Home image', 'width' => 100, 'height' => 60, 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'home-image'))),
                            array('id' => 'button:home:one', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Read more', 'width' => 80, 'height' => 24, 'fontSize' => 16, 'fontWeight' => 700),
                            array('id' => 'button:home:two', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Subscribe', 'width' => 80, 'height' => 24, 'fontSize' => 16, 'fontWeight' => 700),
                            array('id' => 'vector:home-large', 'type' => 'VECTOR', 'name' => 'Home Large Vector', 'width' => 10, 'height' => 10, 'figma_vector_paths' => array(array('data' => $externalizedVectorPath, 'source' => 'strokeGeometry'))),
                        ),
                    ),
                    array(
                        'id'       => 'frame:about',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 1440,
                        'height'   => 900,
                        'children' => array(
                            array('id' => 'text:about', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About Hero', 'fontName' => array('family' => 'Example Sans', 'style' => 'Bold'), 'fontSize' => 20),
                            array('id' => 'image:about', 'type' => 'RECTANGLE', 'name' => 'About image', 'width' => 100, 'height' => 60, 'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'about-image'))),
                            array('id' => 'button:about:one', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Read more', 'width' => 96, 'height' => 28, 'fontSize' => 18, 'fontWeight' => 500),
                            array('id' => 'button:about:two', 'type' => 'TEXT', 'name' => 'Button', 'characters' => 'Subscribe', 'width' => 96, 'height' => 28, 'fontSize' => 18, 'fontWeight' => 500),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page' => true,
        'frame_ids' => array('frame:home', 'frame:about'),
        'entry_frame_id' => 'frame:home',
        'font_css' => '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}',
        'generated_render_evidence' => array(
            'schema' => 'homeboy/static-artifact-render-evidence/v1',
            'entrypoints' => array(
                array(
                    'page_path' => 'index.html',
                    'elements' => array(
                        array('node_id' => 'text:home', 'computed_style' => array('font-family' => 'Example Sans', 'font-size' => '20px', 'font-weight' => '400')),
                    ),
                ),
                array(
                    'page_path' => 'about.html',
                    'elements' => array(
                        array('node_id' => 'text:about', 'computed_style' => array('font-family' => 'Arial, sans-serif', 'font-size' => '20px', 'font-weight' => '700')),
                    ),
                ),
            ),
        ),
    ));
    $multiPageIndex = $fileContent($multiPageResult, 'index.html');
    $multiPageAbout = $fileContent($multiPageResult, 'about.html');
    $multiPageStyle = $fileContent($multiPageResult, 'style.css');
    $multiPageAssetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $multiPageResult['assets'] ?? array());
    $assert('success' === ($multiPageResult['status'] ?? null), 'multi-page-transform-success');
    $assert(str_contains($multiPageIndex, 'Home Hero'), 'multi-page-index-renders-entry-frame');
    $assert(! str_contains($multiPageIndex, 'About Hero'), 'multi-page-index-omits-other-frame');
    $assert(str_contains($multiPageAbout, 'About Hero'), 'multi-page-about-renders-second-frame');
    $assert(str_contains($multiPageIndex, '<style data-figma-transformer-css="true">') && str_contains($multiPageIndex, '.figma-node-frame-home-home'), 'multi-page-index-inlines-page-css');
    $assert(str_contains($multiPageStyle, '.figma-node-frame-home-home'), 'multi-page-shared-css-home');
    $assert(str_contains($multiPageStyle, '.figma-node-frame-about-about'), 'multi-page-shared-css-about');
    preg_match_all('/\.button-[0-9a-f]{8}\{/', $multiPageStyle, $multiPageButtonSharedClasses);
    $assert(2 === count(array_unique($multiPageButtonSharedClasses[0] ?? array())), 'multi-page-shared-readable-classes-are-hashed');
    $assert(! str_contains($multiPageStyle, '.button{'), 'multi-page-shared-readable-class-base-not-reused');
    $assert(2 === ($multiPageResult['metrics']['page_count'] ?? null), 'multi-page-page-count');
    $assert(2 === ($multiPageResult['source_reports']['compiled_site']['totals']['page_count'] ?? null), 'multi-page-compiled-site-page-count');
    $assert('about.html' === ($multiPageResult['source_reports']['compiled_site']['pages'][1]['path'] ?? null), 'multi-page-compiled-site-page-path');
    $assert(3 === ($multiPageResult['source_reports']['compiled_site']['totals']['asset_count'] ?? null), 'multi-page-compiled-site-asset-count');
    $assert(array('Example Sans') === ($multiPageResult['source_reports']['figma']['html']['font_families'] ?? null), 'multi-page-font-families-aggregated');
    $multiPageFontUsage = $multiPageResult['source_reports']['figma']['html']['font_usage'] ?? array();
    $multiPageCompiledFontUsage = $multiPageResult['source_reports']['compiled_site']['theme']['font_usage'] ?? array();
    $assert('Example Sans' === ($multiPageFontUsage[0]['family'] ?? null) && array(400, 700) === ($multiPageFontUsage[0]['weights'] ?? null), 'multi-page-font-usage-aggregated');
    $assert(2 === ($multiPageFontUsage[0]['text_node_count'] ?? null), 'multi-page-font-usage-node-count-aggregated');
    $assert('Example Sans' === ($multiPageCompiledFontUsage[0]['family'] ?? null) && array(400, 700) === ($multiPageCompiledFontUsage[0]['weights'] ?? null), 'multi-page-compiled-site-font-usage');
    $assert(in_array('assets/home-image.png', $multiPageAssetPaths, true), 'multi-page-home-asset');
    $assert(in_array('assets/about-image.png', $multiPageAssetPaths, true), 'multi-page-about-asset');
    $assert(! in_array('assets/unused-image.png', $multiPageAssetPaths, true), 'multi-page-unused-asset-filtered');
    $multiPageTransformDiagnostics = $multiPageResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert('blocks-engine/figma-transformer/transform-diagnostics/v1' === ($multiPageTransformDiagnostics['schema'] ?? null), 'multi-page-transform-diagnostics-schema');
    $assert('multi_page' === ($multiPageTransformDiagnostics['scope'] ?? null), 'multi-page-transform-diagnostics-scope');
    $assert(2 === count($multiPageTransformDiagnostics['pages'] ?? array()), 'multi-page-transform-diagnostics-pages');
    $assert('about.html' === ($multiPageTransformDiagnostics['pages'][1]['page_path'] ?? null), 'multi-page-transform-diagnostics-page-path');
    $assert(2 === ($multiPageTransformDiagnostics['images']['paint_refs'] ?? null), 'multi-page-transform-diagnostics-image-paints');
    $assert(2 === ($multiPageTransformDiagnostics['images']['resolved_assets'] ?? null), 'multi-page-transform-diagnostics-resolved-assets');
    $assert(3 === ($multiPageTransformDiagnostics['assets']['emitted_files'] ?? null), 'multi-page-transform-diagnostics-emitted-assets');
    $assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-count');
    $assert(($multiPageTransformDiagnostics['generated_svg_assets']['gzip_bytes'] ?? 0) > 0, 'multi-page-transform-diagnostics-generated-svg-gzip-bytes');
    $assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['path_element_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-path-element-count');
    $assert(($multiPageTransformDiagnostics['generated_svg_assets']['path_data_bytes'] ?? 0) > 65536, 'multi-page-transform-diagnostics-generated-svg-path-data-bytes');
    $assert(1 === ($multiPageTransformDiagnostics['generated_svg_assets']['unique_path_data_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-unique-path-data-count');
    $assert(0 === ($multiPageTransformDiagnostics['generated_svg_assets']['duplicate_path_data_count'] ?? null), 'multi-page-transform-diagnostics-generated-svg-duplicate-path-data-count');
    $assert('selected_frames' === ($multiPageTransformDiagnostics['selection']['mode'] ?? null), 'multi-page-transform-diagnostics-selection-mode');
    $assert('frame:home' === ($multiPageTransformDiagnostics['selection']['selected_frames'][0]['frame_id'] ?? null), 'multi-page-transform-diagnostics-entry-frame-selection');
    $assert('about.html' === ($multiPageTransformDiagnostics['selection']['selected_frames'][1]['path'] ?? null), 'multi-page-transform-diagnostics-about-selection-path');
    $assert(1 === ($multiPageTransformDiagnostics['layout']['render_style_mismatch_count'] ?? null), 'multi-page-render-style-mismatch-aggregated');
    $assert('fail' === ($multiPageTransformDiagnostics['layout']['render_style_mismatch_status'] ?? null), 'multi-page-render-style-status-aggregated');
    $assert(1 === ($multiPageTransformDiagnostics['layout']['render_style']['summary']['font_mismatch_count'] ?? null), 'multi-page-render-style-font-count-aggregated');
    $assert(in_array('render_style_mismatch', array_map(static fn (array $signal): string => (string) ($signal['code'] ?? ''), $multiPageTransformDiagnostics['artifact_quality']['signals'] ?? array()), true), 'multi-page-render-style-artifact-quality-signal');
    $assert('warn' === ($multiPageTransformDiagnostics['artifact_quality']['quality_status'] ?? null), 'multi-page-transform-diagnostics-quality-status-warn');
    
    // RESPONSIVE PAGE ASSEMBLY — LIVE WIRING (#247): a source whose section holds
    // "Home – Desktop" + "Home – Mobile" sibling frames must (1) PAIR into ONE
    // responsive page in the planner (generic name-normalization + device hint +
    // width signals, NOT hardcoded ids) and (2) flow that grouping through the LIVE
    // transform so the emitted page carries the base (desktop) layout PLUS an
    // `@media (max-width: …)` block for the mobile breakpoint. This exercises the
    // full path end-to-end (planner grouping -> transformScenegraphPages ->
    // transformResponsivePage -> StaticHtmlEmitter::emitSite -> merged style.css),
    // proving emitSite is no longer dormant in production.
    $responsiveLiveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Live Fixture',
        'nodes' => array(
            array(
                'id'       => 'page:responsive-live',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'section:responsive-live',
                        'type'     => 'SECTION',
                        'name'     => 'Home Section',
                        'children' => array(
                            array(
                                'id'       => 'frame:home-desktop-live',
                                'type'     => 'FRAME',
                                'name'     => 'Home – Desktop',
                                'width'    => 1440,
                                'height'   => 3000,
                                'children' => array(
                                    array('id' => 'card:home-desktop', 'type' => 'RECTANGLE', 'name' => 'Hero Card', 'width' => 1200, 'height' => 400, 'backgroundColor' => array('r' => 1.0, 'g' => 0.0, 'b' => 0.0, 'a' => 1.0)),
                                    array('id' => 'text:home-desktop', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'Welcome home', 'fontSize' => 32),
                                ),
                            ),
                            array(
                                'id'       => 'frame:home-mobile-live',
                                'type'     => 'FRAME',
                                'name'     => 'Home – Mobile',
                                'width'    => 390,
                                'height'   => 3200,
                                'children' => array(
                                    array('id' => 'card:home-mobile', 'type' => 'RECTANGLE', 'name' => 'Hero Card', 'width' => 350, 'height' => 500, 'backgroundColor' => array('r' => 0.0, 'g' => 1.0, 'b' => 0.0, 'a' => 1.0)),
                                    array('id' => 'text:home-mobile', 'type' => 'TEXT', 'name' => 'Hero copy', 'characters' => 'Welcome home', 'fontSize' => 32),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page'        => true,
        'include_all_pages' => true,
    ));
    $responsiveLiveIndex = $fileContent($responsiveLiveResult, 'index.html');
    $responsiveLiveStyle = $fileContent($responsiveLiveResult, 'style.css');
    $assert(in_array($responsiveLiveResult['status'] ?? null, array('success', 'success_with_warnings'), true), 'responsive-live-transform-success');
    // Desktop + mobile siblings paired into ONE page (not two).
    $assert(1 === ($responsiveLiveResult['metrics']['page_count'] ?? null), 'responsive-live-single-page');
    $assert('' !== $responsiveLiveIndex, 'responsive-live-index-emitted');
    $assert('' !== $responsiveLiveStyle, 'responsive-live-stylesheet-emitted');
    // Base styles present (the widest/desktop variant drives the base layout).
    $assert(str_contains($responsiveLiveStyle, '.figma-root'), 'responsive-live-base-styles-present');
    $assert(str_contains($responsiveLiveStyle, 'width:1200px') || str_contains($responsiveLiveStyle, 'width:1200px;'), 'responsive-live-base-uses-desktop-width');
    // The merged stylesheet carries an `@media` block (the mobile breakpoint),
    // proving the responsive emission fired through the LIVE transform path.
    // desktop=1440, mobile=390: midpoint = round((1440+390)/2) = 915.
    $assert(str_contains($responsiveLiveStyle, '@media'), 'responsive-live-media-block-present');
    $assert(str_contains($responsiveLiveStyle, '@media (max-width:915px)'), 'responsive-live-mobile-media-query');
    // `@media` block survived chunk merging intact (balanced braces, base before media).
    $assert(strpos($responsiveLiveStyle, '.figma-root') < strpos($responsiveLiveStyle, '@media'), 'responsive-live-base-precedes-media');
    $assert(substr_count($responsiveLiveStyle, '{') === substr_count($responsiveLiveStyle, '}'), 'responsive-live-balanced-braces');
    $responsiveLivePagePlan = $responsiveLiveResult['source_reports']['figma']['pages'] ?? array();
    $assert(true === ($responsiveLivePagePlan['pages'][0]['responsive'] ?? null), 'responsive-live-plan-flagged-responsive');
    $assert(2 === ($responsiveLivePagePlan['pages'][0]['breakpoint_count'] ?? null), 'responsive-live-plan-two-breakpoints');
    
    // SINGLE-FRAME PARITY: a source with one frame still produces ONE
    // non-responsive page with NO `@media` block (the live wiring only fires for
    // detected responsive variant-groups).
    $singleFrameLiveResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Single Frame Live Fixture',
        'nodes' => array(
            array(
                'id'       => 'page:single-live',
                'type'     => 'CANVAS',
                'name'     => 'Site',
                'children' => array(
                    array(
                        'id'       => 'frame:about-live',
                        'type'     => 'FRAME',
                        'name'     => 'About',
                        'width'    => 1440,
                        'height'   => 2000,
                        'children' => array(
                            array('id' => 'card:about-live', 'type' => 'RECTANGLE', 'name' => 'About Card', 'width' => 1100, 'height' => 300, 'backgroundColor' => array('r' => 0.0, 'g' => 0.0, 'b' => 1.0, 'a' => 1.0)),
                            array('id' => 'text:about-live', 'type' => 'TEXT', 'name' => 'About copy', 'characters' => 'About us', 'fontSize' => 24),
                        ),
                    ),
                ),
            ),
        ),
    ), array(
        'multi_page'        => true,
        'include_all_pages' => true,
    ));
    $singleFrameLiveStyle = $fileContent($singleFrameLiveResult, 'style.css');
    $assert(in_array($singleFrameLiveResult['status'] ?? null, array('success', 'success_with_warnings'), true), 'single-frame-live-transform-success');
    $assert(1 === ($singleFrameLiveResult['metrics']['page_count'] ?? null), 'single-frame-live-single-page');
    $assert('' !== $singleFrameLiveStyle, 'single-frame-live-stylesheet-emitted');
    $assert(! str_contains($singleFrameLiveStyle, '@media'), 'single-frame-live-no-media-block');
    $assert(false === ($singleFrameLiveResult['source_reports']['figma']['pages']['pages'][0]['responsive'] ?? true), 'single-frame-live-plan-not-responsive');
}

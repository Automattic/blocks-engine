<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\CanvasShellResolver;
use Automattic\BlocksEngine\FigmaTransformer\Html\BreakpointDimensionPolicy;
use Automattic\BlocksEngine\FigmaTransformer\Html\CanvasShellDecision;
use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutFrameRoleClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Html\VisualGeometryResolver;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_layout_frame_role_contract(callable $assert): void
{
    $classifier = new LayoutFrameRoleClassifier();

    $root = array(
        'id'     => 'roles:root',
        'box'    => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 1200),
        'layout' => array('display' => 'flex', 'flex_direction' => 'column'),
    );
    $rootBox = is_array($root['box'] ?? null) ? $root['box'] : array();
    $rootLayout = is_array($root['layout'] ?? null) ? $root['layout'] : array();
    $assert(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_ROOT === $classifier->frameWidthRole($rootBox, $rootLayout, null), 'layout-frame-role-full-bleed-root');

    $band = array(
        'id'     => 'roles:band',
        'box'    => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 520),
        'layout' => array('sizing_horizontal' => 'FILL'),
    );
    $bandBox = is_array($band['box'] ?? null) ? $band['box'] : array();
    $bandLayout = is_array($band['layout'] ?? null) ? $band['layout'] : array();
    $assert(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_BAND === $classifier->frameWidthRole($bandBox, $bandLayout, $root), 'layout-frame-role-full-bleed-band');

    $flowBandLayout = array('display' => 'flex', 'flex_direction' => 'column');
    $assert($classifier->roleUsesFlowHeight(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_ROOT, $flowBandLayout), 'layout-frame-role-full-bleed-root-uses-flow-height');
    $assert($classifier->roleUsesFlowHeight(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_BAND, $flowBandLayout), 'layout-frame-role-full-bleed-band-uses-flow-height');
    $assert($classifier->roleUsesFlowHeight(LayoutFrameRoleClassifier::ROLE_CENTERED_SHELL, $flowBandLayout), 'layout-frame-role-centered-shell-uses-flow-height');
    $assert(! $classifier->roleUsesFlowHeight(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_BAND, array('display' => 'flex', 'flex_direction' => 'row')), 'layout-frame-role-row-band-keeps-fixed-height');
    $assert(! $classifier->roleUsesFlowHeight(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_BAND, array('display' => 'flex', 'flex_direction' => 'column', 'sizing_vertical' => 'FIXED')), 'layout-frame-role-explicit-fixed-height-preserved');
    $assert(! $classifier->roleUsesFlowHeight(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_BAND, array('display' => 'flex', 'flex_direction' => 'column', 'clips_content' => true)), 'layout-frame-role-clipped-height-preserved');

    $narrowCard = array('x' => 0, 'y' => 0, 'width' => 420, 'height' => 240);
    $assert(LayoutFrameRoleClassifier::ROLE_INTRINSIC === $classifier->frameWidthRole($narrowCard, array(), $root), 'layout-frame-role-narrow-card-intrinsic');

    $backgroundBox = array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 520);
    $backgroundLayout = array('positioning' => 'absolute');
    $assert(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_CANVAS_CHILD === $classifier->canvasChildRole($backgroundBox, $backgroundLayout, $band, true, true), 'layout-frame-role-full-bleed-canvas-child');

    $visualGeometry = new VisualGeometryResolver(new LayoutIntentClassifier());
    $reflectedFullBleedChild = array(
        'id'        => 'roles:reflected-bg',
        'box'       => array('x' => 1440, 'y' => 0, 'width' => 1440, 'height' => 520),
        'figma_box' => array('transform' => array('m00' => -1, 'm01' => 0, 'm02' => 0, 'm10' => 0, 'm11' => 1, 'm12' => 0)),
    );
    $assert($visualGeometry->isVisualFullWidthCanvasChild($reflectedFullBleedChild, $band, true), 'layout-frame-role-reflected-visual-full-bleed-canvas-child');
    $assert($visualGeometry->isHorizontallyReflected($reflectedFullBleedChild), 'layout-frame-role-reflected-visual-full-bleed-detects-horizontal-reflection');
    $reflectedBreakoutDecision = (new BreakpointDimensionPolicy())->fullBleedViewportBreakoutDecision(new CanvasShellDecision(
        LayoutFrameRoleClassifier::ROLE_INTRINSIC,
        LayoutFrameRoleClassifier::ROLE_FULL_BLEED_CANVAS_CHILD,
        true,
        true,
        true,
        false,
        false,
        false,
        false,
        true,
    ));
    $assert(in_array('margin-left:50vw', $reflectedBreakoutDecision['declarations'], true), 'layout-frame-role-reflected-full-bleed-breakout-anchors-end-edge');

    $contentShellBox = array('x' => 112, 'y' => 80, 'width' => 1216, 'height' => 240);
    $contentShellLayout = array('positioning' => 'absolute');
    $assert(LayoutFrameRoleClassifier::ROLE_CENTERED_SHELL === $classifier->canvasChildRole($contentShellBox, $contentShellLayout, $band, true, true), 'layout-frame-role-centered-shell');

    $paddedBand = $band;
    $paddedBand['layout'] = array('sizing_horizontal' => 'FILL', 'padding' => array('left' => 135, 'right' => 135));
    $paddedContentShellBox = array('x' => 0, 'y' => 16, 'width' => 1170, 'height' => 48);
    $assert(LayoutFrameRoleClassifier::ROLE_CENTERED_SHELL === $classifier->canvasChildRole($paddedContentShellBox, array(), $paddedBand, true, false), 'layout-frame-role-centered-shell-from-symmetric-parent-padding');

    $offCenterShellBox = array('x' => 64, 'y' => 80, 'width' => 1216, 'height' => 240);
    $assert(LayoutFrameRoleClassifier::ROLE_INTRINSIC === $classifier->canvasChildRole($offCenterShellBox, $contentShellLayout, $band, true, true), 'layout-frame-role-off-center-shell-stays-intrinsic');

    $nonFluidParentShell = $classifier->canvasChildRole($contentShellBox, $contentShellLayout, $band, false, true);
    $assert(LayoutFrameRoleClassifier::ROLE_INTRINSIC === $nonFluidParentShell, 'layout-frame-role-centered-shell-requires-fluid-parent');

    $resolver = new CanvasShellResolver(
        $classifier,
        static fn (array $node): bool => true === (($node['layout']['freeform'] ?? false)),
        static fn (array $node): bool => true === (($node['layout']['freeform_uses_flow'] ?? false)),
        static function (array $node): bool {
            foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
                if ( is_array($child) && 'absolute' === (($child['layout']['positioning'] ?? null)) ) {
                    return true;
                }
            }
            return false;
        },
        static fn (array $node): bool => true === (($node['layout']['has_decorative_underlay'] ?? false)),
        $visualGeometry,
    );
    $freeformBand = $band;
    $freeformBand['layout']['freeform'] = true;
    $freeformBand['layout']['sizing_horizontal'] = 'FILL';
    $backgroundNode = array('id' => 'roles:bg', 'type' => 'FRAME', 'box' => $backgroundBox, 'layout' => $backgroundLayout);
    $backgroundDecision = $resolver->resolve($backgroundNode, $freeformBand, $root);
    $assert($backgroundDecision->parentUsesFluidCanvasCoordinates, 'canvas-shell-decision-parent-uses-fluid-coordinates');
    $assert($backgroundDecision->fullBleedCanvasChild, 'canvas-shell-decision-full-bleed-child');
    $assert('full_bleed_canvas_child_viewport_breakout' === $resolver->fullBleedViewportBreakoutDecision($backgroundDecision)['reason_code'], 'canvas-shell-decision-breakout-reason-code');
    $assert(array('left:50%', 'margin-left:-50vw') === $resolver->fullBleedViewportBreakoutStyles($backgroundDecision), 'canvas-shell-decision-breakout-styles');

    $reflectedBackgroundNode = array('id' => 'roles:reflected-bg', 'type' => 'FRAME', 'box' => $reflectedFullBleedChild['box'], 'figma_box' => $reflectedFullBleedChild['figma_box'], 'layout' => array());
    $reflectedBackgroundDecision = $resolver->resolve($reflectedBackgroundNode, $freeformBand, $root);
    $assert($reflectedBackgroundDecision->fullBleedCanvasChild, 'canvas-shell-decision-reflected-visual-full-bleed-child');
    $assert($reflectedBackgroundDecision->fullBleedCanvasChildReflected, 'canvas-shell-decision-reflected-full-bleed-child-reflection-flag');
    $assert(array('left:50%', 'margin-left:50vw') === $resolver->fullBleedViewportBreakoutStyles($reflectedBackgroundDecision), 'canvas-shell-decision-reflected-breakout-styles');

    $flowBand = $band;
    $flowBand['layout']['freeform_uses_flow'] = true;
    $contentShellNode = array('id' => 'roles:shell', 'type' => 'FRAME', 'box' => $contentShellBox, 'layout' => array());
    $contentShellDecision = $resolver->resolve($contentShellNode, $flowBand, $root);
    $assert($contentShellDecision->responsiveCenteredFlowShell, 'canvas-shell-decision-responsive-centered-flow-shell');
    $assert($contentShellDecision->responsiveCenteredFlowWidth, 'canvas-shell-decision-responsive-centered-flow-width');

    $intent = new LayoutIntentClassifier();
    $chromeRoot = array(
        'id'       => 'chrome:root',
        'type'     => 'FRAME',
        'name'     => 'Home Page',
        'box'      => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 1200),
        'children' => array(
            array(
                'id'       => 'chrome:header',
                'type'     => 'FRAME',
                'name'     => 'Top Bar',
                'box'      => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 96),
                'children' => array(
                    array('id' => 'chrome:logo', 'type' => 'TEXT', 'name' => 'Brand Logo', 'characters' => 'Brand'),
                    array('id' => 'chrome:link', 'type' => 'TEXT', 'name' => 'Menu Item', 'characters' => 'Home', 'figma_link' => array('url' => '/')),
                ),
            ),
            array(
                'id'       => 'chrome:main',
                'type'     => 'FRAME',
                'name'     => 'Content',
                'box'      => array('x' => 0, 'y' => 160, 'width' => 1440, 'height' => 800),
                'children' => array(array('id' => 'chrome:main-text', 'type' => 'TEXT', 'characters' => 'Body')),
            ),
            array(
                'id'       => 'chrome:footer',
                'type'     => 'FRAME',
                'name'     => 'Bottom Bar',
                'box'      => array('x' => 0, 'y' => 1080, 'width' => 1440, 'height' => 120),
                'children' => array(array('id' => 'chrome:legal', 'type' => 'TEXT', 'characters' => 'Copyright 2026. All rights reserved.')),
            ),
        ),
    );
    $assert(LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $intent->chromeGroupRole($chromeRoot['children'][0], $chromeRoot, 1), 'layout-intent-top-logo-region-classifies-header');
    $assert(LayoutIntentClassifier::CHROME_GROUP_ROLE_FOOTER === $intent->chromeGroupRole($chromeRoot['children'][2], $chromeRoot, 1), 'layout-intent-bottom-legal-region-classifies-footer');
    $assert(LayoutIntentClassifier::LAYER_ROLE_CHROME === $intent->siblingLayerRole($chromeRoot['children'][0], $chromeRoot), 'layout-intent-top-header-layer-is-chrome');

    $nav = array(
        'id'       => 'chrome:nav',
        'type'     => 'FRAME',
        'name'     => 'Primary Navigation',
        'children' => array(
            array('id' => 'chrome:nav-home', 'type' => 'TEXT', 'characters' => 'Home', 'figma_link' => array('url' => '/')),
            array('id' => 'chrome:nav-about', 'type' => 'TEXT', 'characters' => 'About', 'figma_link' => array('url' => '/about')),
        ),
    );
    $assert(LayoutIntentClassifier::CHROME_GROUP_ROLE_NAVIGATION === $intent->chromeGroupRole($nav, null, 1), 'layout-intent-link-cluster-classifies-navigation');

    $social = array(
        'id'       => 'chrome:social',
        'type'     => 'FRAME',
        'name'     => 'Social Links',
        'children' => array(
            array('id' => 'chrome:facebook', 'type' => 'VECTOR', 'name' => 'Facebook', 'width' => 24, 'height' => 24, 'figma_link' => array('url' => 'https://facebook.example')),
            array('id' => 'chrome:instagram', 'type' => 'VECTOR', 'name' => 'Instagram', 'width' => 24, 'height' => 24, 'figma_link' => array('url' => 'https://instagram.example')),
        ),
    );
    $assert(LayoutIntentClassifier::CHROME_GROUP_ROLE_SOCIAL === $intent->chromeGroupRole($social, null, 1), 'layout-intent-social-icon-cluster-classifies-social');

    $cta = array(
        'id'       => 'chrome:cta',
        'type'     => 'FRAME',
        'name'     => 'Call to Action',
        'width'    => 240,
        'height'   => 56,
        'children' => array(array('id' => 'chrome:cta-label', 'type' => 'TEXT', 'characters' => 'Book now')),
    );
    $assert(LayoutIntentClassifier::CHROME_GROUP_ROLE_CTA === $intent->chromeGroupRole($cta, null, 1), 'layout-intent-cta-group-classifies-cta');
}

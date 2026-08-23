<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\CanvasShellResolver;
use Automattic\BlocksEngine\FigmaTransformer\Html\BreakpointDimensionPolicy;
use Automattic\BlocksEngine\FigmaTransformer\Html\CanvasShellDecision;
use Automattic\BlocksEngine\FigmaTransformer\Html\CssPositioningResolver;
use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutFrameRoleClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier;
use Automattic\BlocksEngine\FigmaTransformer\Html\NodeRenderPlan;
use Automattic\BlocksEngine\FigmaTransformer\Html\PositioningStyleResolver;
use Automattic\BlocksEngine\FigmaTransformer\Html\VisualGeometryResolver;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_layout_frame_role_contract(callable $assert): void
{
    $classifier = new LayoutFrameRoleClassifier();

    $root = array(
        'id'     => 'roles:root',
        'type'   => 'FRAME',
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

    $overscanBackgroundBox = array('x' => -3, 'y' => 0, 'width' => 1443, 'height' => 520);
    $assert(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_CANVAS_CHILD === $classifier->canvasChildRole($overscanBackgroundBox, $backgroundLayout, $band, true, true), 'layout-frame-role-full-bleed-canvas-child-with-edge-overscan');

    $visualGeometry = new VisualGeometryResolver(new LayoutIntentClassifier());
    $reflectedFullBleedChild = array(
        'id'        => 'roles:reflected-bg',
        'box'       => array('x' => 1440, 'y' => 0, 'width' => 1440, 'height' => 520),
        'figma_box' => array('transform' => array('m00' => -1, 'm01' => 0, 'm02' => 0, 'm10' => 0, 'm11' => 1, 'm12' => 0)),
    );
    $overscanReflectedFullBleedChild = array(
        'id'        => 'roles:overscan-reflected-bg',
        'box'       => array('x' => 1443, 'y' => 0, 'width' => 1443, 'height' => 520),
        'figma_box' => array('transform' => array('m00' => -1, 'm01' => 0, 'm02' => 0, 'm10' => 0, 'm11' => 1, 'm12' => 0)),
    );
    $assert($visualGeometry->isVisualFullWidthCanvasChild($reflectedFullBleedChild, $band, true), 'layout-frame-role-reflected-visual-full-bleed-canvas-child');
    $assert($visualGeometry->isVisualFullWidthCanvasChild($overscanReflectedFullBleedChild, $band, true), 'layout-frame-role-reflected-visual-full-bleed-canvas-child-with-edge-overscan');
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
    $assert(in_array('margin-left:-50vw', $reflectedBreakoutDecision['declarations'], true), 'layout-frame-role-reflected-full-bleed-breakout-anchors-viewport-start');
    $assert('mirrored_safe_start_edge' === ($reflectedBreakoutDecision['evidence']['viewport_anchor_strategy'] ?? null), 'layout-frame-role-reflected-full-bleed-breakout-evidence-anchor-strategy');
    $assert(true === ($reflectedBreakoutDecision['evidence']['full_bleed_canvas_child_reflected'] ?? null), 'layout-frame-role-reflected-full-bleed-breakout-evidence-reflection');

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
        new LayoutIntentClassifier(),
        static fn (array $node): bool => true === (($node['layout']['freeform_uses_flow'] ?? false)),
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

    $positioningIntent = new LayoutIntentClassifier();
    $positioningResolver = new PositioningStyleResolver(
        new CssPositioningResolver($positioningIntent, static fn (float $value): string => 0.0 === fmod($value, 1.0) ? (string) (int) $value : rtrim(rtrim(sprintf('%.3F', $value), '0'), '.')),
        $resolver,
    );
    $backgroundPlan = new NodeRenderPlan(
        'FRAME',
        array(),
        $positioningIntent->layoutIntent($backgroundNode, $freeformBand),
        $backgroundDecision,
        $positioningIntent->stackingContextPlan($backgroundNode, $freeformBand),
        true,
        false,
        false,
        false,
    );
    $positioningDecision = $positioningResolver->resolve($backgroundNode, $freeformBand, $backgroundBox, $backgroundLayout, $backgroundPlan, array('width:100vw'));
    $assert(null !== $positioningDecision->absolutePositioningDecision, 'positioning-decision-records-absolute-boundary');
    $assert('freeform_parent_absolute_child' === ($positioningDecision->absolutePositioningDecision->reasonCode ?? null), 'positioning-decision-reason-freeform-parent');
    $assert(array('position:absolute', 'top:0px', 'left:50%', 'margin-left:-50vw') === ($positioningDecision->absolutePositioningDecision->declarations ?? array()), 'positioning-decision-declarations-filter-local-and-append-viewport');
    $assert(true === ($positioningDecision->absolutePositioningDecision->suppressedFullBleedHorizontalOffsets ?? null), 'positioning-decision-records-suppressed-local-horizontal-offset');

    $reflectedBackgroundNode = array('id' => 'roles:reflected-bg', 'type' => 'FRAME', 'box' => $reflectedFullBleedChild['box'], 'figma_box' => $reflectedFullBleedChild['figma_box'], 'layout' => array());
    $reflectedBackgroundDecision = $resolver->resolve($reflectedBackgroundNode, $freeformBand, $root);
    $assert($reflectedBackgroundDecision->fullBleedCanvasChild, 'canvas-shell-decision-reflected-visual-full-bleed-child');
    $assert($reflectedBackgroundDecision->fullBleedCanvasChildReflected, 'canvas-shell-decision-reflected-full-bleed-child-reflection-flag');
    $assert(array('left:50%', 'margin-left:-50vw') === $resolver->fullBleedViewportBreakoutStyles($reflectedBackgroundDecision), 'canvas-shell-decision-reflected-breakout-styles');
    $reflectedResolverBreakoutDecision = $resolver->fullBleedViewportBreakoutDecision($reflectedBackgroundDecision);
    $assert(array('left:50%', 'margin-left:-50vw') === ($reflectedResolverBreakoutDecision['evidence']['viewport_anchor_declarations'] ?? null), 'canvas-shell-decision-reflected-breakout-evidence-declarations');

    $absoluteChromeRoot = $root;
    $absoluteChromeRoot['children'] = array($backgroundNode);
    $absoluteChromeDecision = $resolver->resolve($backgroundNode, $absoluteChromeRoot, null);
    $assert($absoluteChromeDecision->parentUsesFluidCanvasCoordinates, 'canvas-shell-decision-root-uses-fluid-coordinates-for-absolute-children');
    $assert($absoluteChromeDecision->fullBleedCanvasChild, 'canvas-shell-decision-root-absolute-child-is-full-bleed');

    $overscanRootBackgroundNode = array('id' => 'roles:root-overscan-bg', 'type' => 'RECTANGLE', 'box' => $overscanBackgroundBox, 'layout' => array('positioning' => 'absolute'));
    $freeformRoot = $root;
    $freeformRoot['layout'] = array();
    $freeformRoot['children'] = array($overscanRootBackgroundNode);
    $freeformRootDecision = $resolver->resolve($overscanRootBackgroundNode, $freeformRoot, null);
    $assert(! $freeformRootDecision->parentUsesFluidCanvasCoordinates, 'canvas-shell-decision-freeform-root-keeps-source-coordinates');
    $assert($freeformRootDecision->fullBleedCanvasChild, 'canvas-shell-decision-freeform-root-overscan-child-is-full-bleed');
    $assert(array('left:50%', 'margin-left:-50vw') === $resolver->fullBleedViewportBreakoutStyles($freeformRootDecision), 'canvas-shell-decision-freeform-root-overscan-child-breakout-styles');
    $freeformRootBreakoutDecision = $resolver->fullBleedViewportBreakoutDecision($freeformRootDecision);
    $assert(false === ($freeformRootBreakoutDecision['evidence']['parent_uses_fluid_canvas_coordinates'] ?? null), 'canvas-shell-decision-freeform-root-overscan-breakout-evidence-source-coordinates');
    $assert(LayoutFrameRoleClassifier::ROLE_FULL_BLEED_CANVAS_CHILD === ($freeformRootBreakoutDecision['evidence']['canvas_child_role'] ?? null), 'canvas-shell-decision-freeform-root-overscan-breakout-evidence-role');

    $standaloneRowRoot = $absoluteChromeRoot;
    $standaloneRowRoot['layout'] = array('display' => 'flex', 'flex_direction' => 'row');
    $standaloneRowDecision = $resolver->resolve($backgroundNode, $standaloneRowRoot, null);
    $assert(! $standaloneRowDecision->fullBleedCanvasChild, 'canvas-shell-decision-standalone-row-root-keeps-source-width-children');

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

    $clonedHeaderRoot = array(
        'id'       => 'chrome:clone-root',
        'type'     => 'FRAME',
        'name'     => 'Home',
        'box'      => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 3200),
        'children' => array(
            array('id' => 'chrome:clone-hero', 'type' => 'FRAME', 'name' => 'Hero', 'box' => array('x' => 0, 'y' => 61, 'width' => 1440, 'height' => 758)),
            array('id' => 'chrome:clone-header', 'type' => 'INSTANCE', 'name' => 'Header', 'box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 145)),
        ),
    );
    $clonedHeaderPlan = $intent->siblingLayerStackPlan($clonedHeaderRoot['children'][1], $clonedHeaderRoot);
    $assert(LayoutIntentClassifier::LAYER_ROLE_CHROME === ($clonedHeaderPlan['role'] ?? null), 'layout-intent-top-header-instance-layer-is-chrome');
    $assert(true === ($clonedHeaderPlan['overlaps_sibling'] ?? null), 'layout-intent-top-header-instance-overlaps-hero');
    $assert(2 === ($clonedHeaderPlan['z_index'] ?? null), 'layout-intent-top-header-instance-ranks-above-hero');

    $headerUnderWedgeRoot = $clonedHeaderRoot;
    $headerUnderWedgeRoot['children'] = array(
        array('id' => 'chrome:ordered-header', 'type' => 'INSTANCE', 'name' => 'Header', 'box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 145)),
        array('id' => 'chrome:ordered-wedge', 'type' => 'RECTANGLE', 'name' => 'Rectangle', 'box' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 148)),
    );
    $headerUnderWedgePlan = $intent->siblingLayerStackPlan($headerUnderWedgeRoot['children'][0], $headerUnderWedgeRoot);
    $assert(LayoutIntentClassifier::LAYER_ROLE_CHROME === ($headerUnderWedgePlan['role'] ?? null), 'layout-intent-top-header-instance-remains-chrome-before-wedge');
    $assert(1 === ($headerUnderWedgePlan['z_index'] ?? null), 'layout-intent-top-header-instance-preserves-source-order-before-wedge');

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

    $pricingGrid = array(
        'id'       => 'layout:pricing',
        'type'     => 'FRAME',
        'name'     => 'Pricing plans grid',
        'box'      => array('x' => 0, 'y' => 0, 'width' => 960, 'height' => 420),
        'children' => array(
            array('id' => 'layout:basic', 'type' => 'FRAME', 'name' => 'Basic plan card', 'box' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 180), 'children' => array(array('id' => 'layout:basic-title', 'type' => 'TEXT', 'characters' => 'Basic'), array('id' => 'layout:basic-price', 'type' => 'TEXT', 'characters' => '$19/mo'))),
            array('id' => 'layout:pro', 'type' => 'FRAME', 'name' => 'Pro plan card', 'box' => array('x' => 330, 'y' => 0, 'width' => 300, 'height' => 180), 'children' => array(array('id' => 'layout:pro-title', 'type' => 'TEXT', 'characters' => 'Pro'), array('id' => 'layout:pro-price', 'type' => 'TEXT', 'characters' => '$49/mo'))),
            array('id' => 'layout:team', 'type' => 'FRAME', 'name' => 'Team plan card', 'box' => array('x' => 0, 'y' => 220, 'width' => 300, 'height' => 180), 'children' => array(array('id' => 'layout:team-title', 'type' => 'TEXT', 'characters' => 'Team'), array('id' => 'layout:team-price', 'type' => 'TEXT', 'characters' => '$99/mo'))),
            array('id' => 'layout:enterprise', 'type' => 'FRAME', 'name' => 'Enterprise plan card', 'box' => array('x' => 330, 'y' => 220, 'width' => 300, 'height' => 180), 'children' => array(array('id' => 'layout:enterprise-title', 'type' => 'TEXT', 'characters' => 'Enterprise'), array('id' => 'layout:enterprise-price', 'type' => 'TEXT', 'characters' => 'Contact us'))),
        ),
    );
    $pricingIntent = $intent->layoutIntent($pricingGrid);
    $assert(LayoutIntentClassifier::LAYOUT_INTENT_PRICING_GRID === ($pricingIntent['intent'] ?? null), 'layout-intent-pricing-grid-classifies');
    $assert('grid' === ($pricingIntent['display'] ?? null), 'layout-intent-pricing-grid-display');
    $assert(2 === ($pricingIntent['column_count'] ?? null), 'layout-intent-pricing-grid-columns');

    $navIntent = $intent->layoutIntent($nav);
    $assert(LayoutIntentClassifier::LAYOUT_INTENT_NAV_ROW === ($navIntent['intent'] ?? null), 'layout-intent-nav-row-classifies');

    $artifactResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Layout Intent Fixture',
        'nodes' => array(
            array(
                'id'       => 'intent:root',
                'type'     => 'FRAME',
                'name'     => 'Pricing section',
                'width'    => 960,
                'height'   => 420,
                'children' => array(
                    array('id' => 'intent:basic', 'type' => 'FRAME', 'name' => 'Basic pricing card', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 180, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(array('id' => 'intent:basic-title', 'type' => 'TEXT', 'text' => 'Basic'), array('id' => 'intent:basic-price', 'type' => 'TEXT', 'text' => '$19/mo'))),
                    array('id' => 'intent:pro', 'type' => 'FRAME', 'name' => 'Pro pricing card', 'x' => 330, 'y' => 0, 'width' => 300, 'height' => 180, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(array('id' => 'intent:pro-title', 'type' => 'TEXT', 'text' => 'Pro'), array('id' => 'intent:pro-price', 'type' => 'TEXT', 'text' => '$49/mo'))),
                    array('id' => 'intent:team', 'type' => 'FRAME', 'name' => 'Team pricing card', 'x' => 0, 'y' => 220, 'width' => 300, 'height' => 180, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(array('id' => 'intent:team-title', 'type' => 'TEXT', 'text' => 'Team'), array('id' => 'intent:team-price', 'type' => 'TEXT', 'text' => '$99/mo'))),
                    array('id' => 'intent:enterprise', 'type' => 'FRAME', 'name' => 'Enterprise pricing card', 'x' => 330, 'y' => 220, 'width' => 300, 'height' => 180, 'layoutPositioning' => 'ABSOLUTE', 'children' => array(array('id' => 'intent:enterprise-title', 'type' => 'TEXT', 'text' => 'Enterprise'), array('id' => 'intent:enterprise-price', 'type' => 'TEXT', 'text' => 'Contact us'))),
                ),
            ),
        ),
    ));
    $artifactHtml = blocks_engine_figma_transformer_contract_file_content($artifactResult, 'index.html');
    $artifactCss = blocks_engine_figma_transformer_contract_file_content($artifactResult, 'style.css');
    $pricingRule = blocks_engine_figma_transformer_contract_css_rule($artifactCss, '.figma-node-intent-root-pricing-section');
    $basicRule = blocks_engine_figma_transformer_contract_css_rule($artifactCss, '.figma-node-intent-basic-basic-pricing-card');
    $assert(str_contains($artifactHtml, 'data-figma-layout-intent="pricing-grid"'), 'layout-intent-artifact-emits-intent-attribute');
    $assert(str_contains($artifactHtml, 'data-figma-layout-display="grid"'), 'layout-intent-artifact-emits-layout-display');
    $assert(str_contains($artifactHtml, 'data-figma-collection="pricing"'), 'layout-intent-artifact-emits-collection');
    $assert(str_contains($pricingRule, 'display:grid') && str_contains($pricingRule, 'grid-template-columns:repeat(2,minmax(0,1fr))') && str_contains($pricingRule, 'min-height:420px') && ! str_contains($pricingRule, ';height:420px'), 'layout-intent-artifact-grid-flow-css');
    $assert(! str_contains($basicRule, 'position:absolute'), 'layout-intent-artifact-grid-child-uses-flow-positioning');
}

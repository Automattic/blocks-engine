<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutFrameRoleClassifier;

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
}

<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\StackingContextPolicy;
use Automattic\BlocksEngine\FigmaTransformer\Html\LayoutIntentClassifier;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_stacking_context_policy_contract(callable $assert): void
{
    $policy = new StackingContextPolicy();

    $decorativeUnderlay = $policy->zIndexDecision(true, 9, null);
    $assert(0 === $decorativeUnderlay['z_index'], 'stacking-policy-decorative-underlay-base-z-index');
    $assert(StackingContextPolicy::STACK_REASON_DECORATIVE_UNDERLAY === $decorativeUnderlay['reason'], 'stacking-policy-decorative-underlay-reason');

    $rankedUnderlay = $policy->zIndexDecision(true, 9, 3);
    $assert(3 === $rankedUnderlay['z_index'], 'stacking-policy-ranked-underlay-preserves-sibling-rank');
    $assert(StackingContextPolicy::STACK_REASON_SIBLING_LAYER_RANK === $rankedUnderlay['reason'], 'stacking-policy-ranked-underlay-reason');

    $sourceZIndex = $policy->zIndexDecision(false, 9, 3);
    $assert(3 === $sourceZIndex['z_index'], 'stacking-policy-sibling-rank-wins-over-source-z-index');
    $assert(StackingContextPolicy::STACK_REASON_SIBLING_LAYER_RANK === $sourceZIndex['reason'], 'stacking-policy-sibling-rank-over-source-z-index-reason');

    $sourceZIndexFallback = $policy->zIndexDecision(false, 9, null);
    $assert(9 === $sourceZIndexFallback['z_index'], 'stacking-policy-source-z-index-fallback');
    $assert(StackingContextPolicy::STACK_REASON_SOURCE_Z_INDEX === $sourceZIndexFallback['reason'], 'stacking-policy-source-z-index-fallback-reason');

    $siblingRank = $policy->zIndexDecision(false, null, 3);
    $assert(3 === $siblingRank['z_index'], 'stacking-policy-sibling-rank-z-index');
    $assert(StackingContextPolicy::STACK_REASON_SIBLING_LAYER_RANK === $siblingRank['reason'], 'stacking-policy-sibling-rank-reason');

    $layoutClassifier = new LayoutIntentClassifier();
    $topStrip = array(
        'id'    => 'hero:top-strip',
        'name'  => 'Rectangle',
        'type'  => 'RECTANGLE',
        'box'   => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 64),
        'fills' => array(array('type' => 'SOLID', 'color' => '#ffcf00')),
    );
    $heroImage = array(
        'id'    => 'hero:image',
        'name'  => 'Image',
        'type'  => 'FRAME',
        'box'   => array('x' => 112, 'y' => 0, 'width' => 1216, 'height' => 760),
        'fills' => array(array('type' => 'IMAGE', 'imageRef' => 'hero-photo')),
    );
    $heroParent = array(
        'id'       => 'hero:parent',
        'name'     => 'Hero Media Stack',
        'type'     => 'FRAME',
        'box'      => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 760),
        'children' => array($topStrip, $heroImage),
    );
    $topStripPlan = $layoutClassifier->siblingLayerStackPlan($topStrip, $heroParent);
    $heroImagePlan = $layoutClassifier->siblingLayerStackPlan($heroImage, $heroParent);
    $assert(true === $topStripPlan['overlaps_sibling'], 'stacking-policy-hero-top-strip-overlap-detected');
    $assert(true === $heroImagePlan['overlaps_sibling'], 'stacking-policy-hero-image-overlap-detected');
    $assert(is_int($topStripPlan['z_index']) && is_int($heroImagePlan['z_index']) && $heroImagePlan['z_index'] > $topStripPlan['z_index'], 'stacking-policy-hero-image-ranks-above-top-strip');
}

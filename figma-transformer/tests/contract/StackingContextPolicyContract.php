<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\StackingContextPolicy;

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
}

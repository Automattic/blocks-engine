<?php
declare(strict_types=1);

/**
 * WrapperDecision (#7471, stage 1 slice 3) owns the group-chain compression,
 * proof-backed, inherited-text and neutral-child coalescing guards, plus the
 * flow-container, author-layout and list-item single-child decisions, the
 * script-state guard and the sole-element-child skip. This pins the
 * inventory (names, stages, order) and the static tables' outcomes. Output
 * parity is pinned by the transform tests named in docs/wrapper-decisions.md.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDecision;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDisposition;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$tables = WrapperDecision::loweringGuardStages();
$assert(array(
    'ancestor_chain_compression',
    'projected_chain_fold',
    'proof_backed_coalescing',
    'inherited_text_coalescing',
    'neutral_child_absorption',
    'single_child_candidate',
    'single_child_retention',
    'author_layout_empty_child',
    'script_state',
    'sole_element_child',
) === array_keys($tables), 'lowering-tables');
$assert(11 === count($tables['proof_backed_coalescing']), 'proof-backed-guard-count', (string) count($tables['proof_backed_coalescing']));
$assert(7 === count($tables['inherited_text_coalescing']), 'inherited-text-guard-count', (string) count($tables['inherited_text_coalescing']));
$assert(30 === count($tables['neutral_child_absorption']), 'neutral-child-guard-count', (string) count($tables['neutral_child_absorption']));
foreach ( $tables as $table => $guards ) {
    $assert(array() === array_diff(array_unique(array_values($guards)), WrapperDecision::STAGES), $table . '-guards-belong-to-known-stages');
}

$never = static fn (): bool => throw new LogicException('guard evaluated after an earlier match');

// Ancestor-chain compression: exemptions short-circuit the budget guard.
$d = WrapperDecision::ancestorChainCompression(true, 5, $never);
$assert($d->isPreserve() && 'native_navigation_chain' === $d->reason, 'ancestor-native-navigation', $d->reason);
$d = WrapperDecision::ancestorChainCompression(false, 1, $never);
$assert($d->isPreserve() && 'single_wrapper_ancestry' === $d->reason, 'ancestor-single', $d->reason);
$d = WrapperDecision::ancestorChainCompression(false, 3, static fn (): bool => true);
$assert($d->isFold() && 'saved_depth_exceeds_nesting_budget' === $d->reason && WrapperDecision::STAGE_RESTATEMENT === $d->stage, 'ancestor-fold', $d->reason);
$assert(! WrapperDecision::ancestorChainCompression(false, 3, static fn (): bool => false)->isFold(), 'ancestor-within-budget');

// Projected chain fold mirrors `!native && (count >= 2 || (count && shell))`.
foreach ( array(
    array(true, 3, true, false),
    array(false, 0, true, false),
    array(false, 1, false, false),
    array(false, 1, true, true),
    array(false, 2, false, true),
) as [$native, $length, $shell, $folds] ) {
    $assert($folds === WrapperDecision::projectedChainFold($native, $length, $shell)->isFold(), 'projected-fold-' . json_encode(array($native, $length, $shell)));
}

// Single-child candidate and retention.
$assert(null === WrapperDecision::singleChildCandidate(array(array('blockName' => 'core/group'))), 'single-child-candidate');
$assert('not_single_child_block' === WrapperDecision::singleChildCandidate(array())?->reason, 'no-child-not-candidate');
$assert('author_layout_topology_changed' === WrapperDecision::singleChildCandidate(array(array()), true)?->reason, 'topology-changed-not-candidate');
$kept = WrapperDisposition::preserve('runtime_dom_target', WrapperDecision::STAGE_IDENTITY);
$assert($kept === WrapperDecision::singleChildRetention($kept, $never), 'retention-preserve-short-circuits');
$folded = WrapperDisposition::fold('no_wrapper_box_or_identity');
$d = WrapperDecision::singleChildRetention($folded, static fn (): bool => true);
$assert($d->isPreserve() && 'author_owned_layout_child' === $d->reason, 'retention-author-layout-child', $d->reason);
$d = WrapperDecision::singleChildRetention($folded, static fn (): bool => false);
$assert($d->isFold() && 'no_wrapper_box_or_identity' === $d->reason, 'retention-fold', $d->reason);

// Author-layout empty child: drop, spacer, or continue.
$d = WrapperDecision::authorLayoutEmptyChild(static fn (): bool => true, $never);
$assert(null !== $d && $d->isDrop() && 'inert_out_of_flow_empty_element' === $d->reason, 'empty-child-drop');
$spacer = WrapperDisposition::preserve('block_identity_or_box', WrapperDecision::STAGE_IDENTITY);
$assert($spacer === WrapperDecision::authorLayoutEmptyChild(static fn (): bool => false, static fn (): WrapperDisposition => $spacer), 'empty-child-spacer');
$assert(null === WrapperDecision::authorLayoutEmptyChild(static fn (): bool => false, static fn (): WrapperDisposition => WrapperDisposition::drop('has_rendered_text')), 'empty-child-continues');

// Inherited-text values guard.
$assert(! WrapperDecision::inheritedTextValues(array())->isCoalesce(), 'inherited-text-empty');
$assert(WrapperDecision::inheritedTextValues(array('color' => 'red'))->isCoalesce(), 'inherited-text-restated');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Wrapper decision lowering tests: ' . $assertions . " passed\n";

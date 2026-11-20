<?php
declare(strict_types=1);

/**
 * WrapperDecision (#7471, stage 1 slice 5) owns the author-layout box
 * chrome and lone styled inline decisions, the synthesized button save
 * wrappers (`css-owned`), the layout-shell wrapper restatement, the element
 * conversion prelude's wrapper dispatch and the RichText styling-hook
 * unwrapping and hoisting. This pins the inventory (names, stages, order)
 * and the tables' outcomes and short-circuits. Output parity is pinned by
 * the transform tests named in docs/wrapper-decisions.md.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDecision;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDisposition;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutParticipation;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$tables = WrapperDecision::layoutGuardStages();
$assert(array(
    'box_chrome_wrapper',
    'lone_styled_inline_in_flow_wrapper',
    'inner_save_wrapper',
    'buttons_save_wrapper',
    'button_save_wrapper',
    'layout_shell_wrapper',
    'prelude_wrapper',
    'rich_text_styling_hook',
    'content_wrapping_span',
) === array_keys($tables), 'layout-tables');
foreach ( $tables as $table => $guards ) {
    $assert(array() === array_diff(array_unique(array_values($guards)), WrapperDecision::STAGES), $table . '-guards-belong-to-known-stages');
}

$never = static fn (): bool => throw new LogicException('guard evaluated after an earlier match');
$yes = static fn (): bool => true;
$no = static fn (): bool => false;

// Box chrome: any non-zero chrome declaration keeps the wrapper's box.
$assert(null === WrapperDecision::boxChromeWrapper(array( 'padding' => '0', 'color' => 'red' )), 'box-chrome-none');
$d = WrapperDecision::boxChromeWrapper(array( 'border-radius' => '4px' ));
$assert(null !== $d && $d->isPreserve() && 'box_chrome_styling' === $d->reason && WrapperDecision::STAGE_GEOMETRY === $d->stage, 'box-chrome', (string) $d?->reason);

// Lone styled inline in a box-chrome flow wrapper.
$doc = new DOMDocument();
$doc->loadHTML('<div id="w"><!-- c --> <span id="s">x</span> </div><div id="v"><span id="t">x</span>tail</div>');
$span = $doc->getElementById('s');
$lone = static fn (DOMElement $element, Closure $context, Closure $inline, Closure $chrome, Closure $addressed): WrapperDisposition => WrapperDecision::loneStyledInlineInFlowWrapper($element, $context, $inline, $chrome, $addressed);
$parentNo = static fn (DOMElement $parent): bool => false;
$parentYes = static fn (DOMElement $parent): bool => true;
$parentNever = static fn (DOMElement $parent): bool => throw new LogicException('parent guard evaluated after an earlier match');
$d = $lone($span, $yes, $parentNever, $parentNever, $never);
$assert($d->isFold() && 'rich_text_inline_context' === $d->reason, 'lone-rich-text-context', $d->reason);
$d = $lone($span, $no, $parentYes, $parentNever, $never);
$assert($d->isFold() && 'inline_parent' === $d->reason && WrapperDecision::STAGE_GEOMETRY === $d->stage, 'lone-inline-parent', $d->reason);
$d = $lone($span, $no, $parentNo, $parentNo, $never);
$assert('parent_without_box_chrome' === $d->reason, 'lone-no-chrome', $d->reason);
$d = $lone($span, $no, $parentNo, $parentYes, $no);
$assert('not_selector_addressed' === $d->reason, 'lone-not-addressed', $d->reason);
$d = $lone($span, $no, $parentNo, $parentYes, $yes);
$assert($d->isPreserve() && 'lone_styled_inline_in_box_chrome_wrapper' === $d->reason, 'lone-preserve', $d->reason);
$d = $lone($doc->getElementById('t'), $no, $parentNo, $parentYes, $yes);
$assert($d->isFold() && 'parent_has_other_content' === $d->reason, 'lone-sibling-text', $d->reason);

// Button save wrappers: the `css-owned` outcome.
$participation = static fn (string $context, bool $positioned = false, bool $native = false, bool $ownsLine = false): LayoutParticipation => new LayoutParticipation($context, 'block', $positioned, $native, false, false, $ownsLine);
$d = WrapperDecision::innerSaveWrapper($participation(LayoutParticipation::FLEX_ITEM, false, true));
$assert($d->isCssOwned() && 'native_control_layout_item' === $d->reason, 'inner-save-flattened', $d->reason);
$assert(WrapperDecision::innerSaveWrapper($participation(LayoutParticipation::BLOCK, false, true))->isPreserve(), 'inner-save-block');
$assert(WrapperDecision::innerSaveWrapper($participation(LayoutParticipation::GRID_ITEM))->isPreserve(), 'inner-save-anchor');

$d = WrapperDecision::buttonsSaveWrapper($participation(LayoutParticipation::INLINE, true));
$assert($d->isCssOwned() && 'positioned_control' === $d->reason, 'buttons-positioned', $d->reason);
$d = WrapperDecision::buttonsSaveWrapper($participation(LayoutParticipation::INLINE));
$assert($d->isCssOwned() && 'inline_control_without_aligned_line' === $d->reason, 'buttons-inline', $d->reason);
$assert(WrapperDecision::buttonsSaveWrapper($participation(LayoutParticipation::INLINE, false, false, true))->isPreserve(), 'buttons-inline-owns-line');
$assert('native_control_layout_item' === WrapperDecision::buttonsSaveWrapper($participation(LayoutParticipation::FLEX_ITEM, false, true))->reason, 'buttons-native-item');
$assert(WrapperDecision::buttonsSaveWrapper($participation(LayoutParticipation::BLOCK))->isPreserve(), 'buttons-block');

$d = WrapperDecision::buttonSaveWrapper($participation(LayoutParticipation::INLINE, true));
$assert($d->isPreserve() && 'positioning_box_for_out_of_flow_control' === $d->reason, 'button-positioned', $d->reason);
$assert('inline_control' === WrapperDecision::buttonSaveWrapper($participation(LayoutParticipation::INLINE))->reason, 'button-inline');
$assert(WrapperDecision::buttonSaveWrapper($participation(LayoutParticipation::GRID_ITEM, false, true))->isCssOwned(), 'button-native-item');
$assert(WrapperDecision::buttonSaveWrapper($participation(LayoutParticipation::FLEX_ITEM))->isPreserve(), 'button-anchor-item');

// The LayoutParticipation predicates read those dispositions, unchanged.
foreach ( array( LayoutParticipation::INLINE, LayoutParticipation::BLOCK, LayoutParticipation::FLEX_ITEM, LayoutParticipation::GRID_ITEM ) as $context ) {
    foreach ( array( false, true ) as $positioned ) {
        foreach ( array( false, true ) as $native ) {
            foreach ( array( false, true ) as $ownsLine ) {
                $p = $participation($context, $positioned, $native, $ownsLine);
                $flattens = $p->isLayoutItem() && $native;
                $label = $context . ($positioned ? '-positioned' : '') . ($native ? '-native' : '') . ($ownsLine ? '-line' : '');
                $assert($flattens === $p->flattensInnerSaveWrapper(), 'flattens-' . $label);
                $assert(( $positioned || ( $p->isInline() && ! $ownsLine ) || $flattens ) === $p->neutralizeButtonsWrapper(), 'buttons-' . $label);
                $assert(( ! $positioned && ( $p->isInline() || $flattens ) ) === $p->neutralizeButtonWrapper(), 'button-' . $label);
            }
        }
    }
}

// Layout-shell wrapper restatement.
$doc = new DOMDocument();
$doc->loadHTML('<x-card id="c"></x-card><label id="l" for="f"></label><section id="s"></section>');
$shell = WrapperDecision::layoutShellWrapper($doc->getElementById('c'), false);
$assert('div' === $shell['tagName'] && 'custom_element_restated_as_div' === $shell['disposition']->reason, 'shell-custom-element', $shell['disposition']->reason);
$shell = WrapperDecision::layoutShellWrapper($doc->getElementById('l'), true);
$assert('div' === $shell['tagName'] && 'field_box_label_restated_as_div' === $shell['disposition']->reason, 'shell-field-label', $shell['disposition']->reason);
$shell = WrapperDecision::layoutShellWrapper($doc->getElementById('l'), false);
$assert('label' === $shell['tagName'] && 'source_tag' === $shell['disposition']->reason, 'shell-label', $shell['disposition']->reason);
$assert('section' === WrapperDecision::layoutShellWrapper($doc->getElementById('s'), true)['tagName'], 'shell-section');
$assert(null === WrapperDecision::layoutShellSourceClasses($doc->getElementById('s')), 'shell-not-carrier');
$assert('runtime_dom_selector_class' === WrapperDecision::layoutShellRestatedClass('blocks-engine-attribute-x', array( 'blocks-engine-attribute-x' => true )), 'shell-runtime-class-first');
$assert('engine_attribute_class' === WrapperDecision::layoutShellRestatedClass('blocks-engine-editor-anchor-1', array()), 'shell-engine-class');
$assert(null === WrapperDecision::layoutShellRestatedClass('card', array()), 'shell-plain-class');

// Element conversion prelude dispatch order.
$dropped = WrapperDisposition::drop('inert_empty_container', WrapperDecision::STAGE_GEOMETRY);
$assert($dropped === WrapperDecision::preludeWrapper(static fn (): ?WrapperDisposition => $dropped, $never, $never), 'prelude-inert-first');
$none = static fn (): ?WrapperDisposition => null;
$d = WrapperDecision::preludeWrapper($none, $yes, $never);
$assert(null !== $d && $d->isPreserve() && 'span_holds_form' === $d->reason, 'prelude-span-form', (string) $d?->reason);
$d = WrapperDecision::preludeWrapper($none, $no, $yes);
$assert(null !== $d && $d->isFold() && 'standalone_inline_layout_leaf' === $d->reason, 'prelude-leaf', (string) $d?->reason);
$assert(null === WrapperDecision::preludeWrapper($none, $no, $no), 'prelude-continues');

// RichText styling hooks: unwrapping and restatement.
$doc = new DOMDocument();
$doc->loadHTML('<font>a</font><font id="f2" color="red">b</font><span id="s">c</span><em id="e" class="x">d</em><x-tag id="u">e</x-tag>');
$bare = $doc->getElementsByTagName('font')->item(0);
$d = WrapperDecision::richTextStylingHook($bare, $never);
$assert($d->isFold() && 'attributeless_font' === $d->reason, 'hook-bare-font', $d->reason);
$d = WrapperDecision::richTextStylingHook($doc->getElementById('f2'), $yes);
$assert($d->isPreserve() && 'restated_on_mark' === $d->reason, 'hook-mark', $d->reason);
$assert('unrepresentable_inline_wrapper' === WrapperDecision::richTextStylingHook($doc->getElementById('s'), $no)->reason, 'hook-span');
$assert('unrepresentable_inline_wrapper' === WrapperDecision::richTextStylingHook($doc->getElementById('u'), $no)->reason, 'hook-unknown');
$assert('rich_text_inline_format' === WrapperDecision::richTextStylingHook($doc->getElementById('e'), $no)->reason, 'hook-format');

$assert(null === WrapperDecision::listItemContentHoisting(false, $never), 'hoist-not-list-item');
$assert('list_item_has_block_content' === WrapperDecision::listItemContentHoisting(true, $yes)?->reason, 'hoist-list-block-content');
$assert(null === WrapperDecision::listItemContentHoisting(true, $no), 'hoist-list-inline');
$d = WrapperDecision::contentWrappingSpan(array(), $yes);
$assert($d->isPreserve() && 'rich_text_safe_identity' === $d->reason, 'hoist-identity', $d->reason);
$assert('rich_text_marker_declaration' === WrapperDecision::contentWrappingSpan(array( '--blocks-engine-richtext-marker' => '1' ), $no)->reason, 'hoist-marker');
$d = WrapperDecision::contentWrappingSpan(array( 'color' => 'red' ), $no);
$assert($d->isFold() && 'content_wrapping_span_hoisted' === $d->reason, 'hoist-fold', $d->reason);

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Wrapper decision layout tests: ' . $assertions . " passed\n";

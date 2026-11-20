<?php
declare(strict_types=1);

/**
 * WrapperDecision (#7471, stage 1 slice 6) owns the last wrapper decisions
 * that were made locally: button label and control wrappers, navigation
 * carrier, flattened and support wrappers, media/text wrappers, captured
 * menu trigger groups, navigation duplicates and close helpers, carousel
 * stage wrappers, and retained custom-wrapper hooks. This pins the inventory (names, stages,
 * order) and the tables' outcomes and short-circuits. Output parity is
 * pinned by the transform tests named in docs/wrapper-decisions.md.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDecision;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$tables = WrapperDecision::patternGuardStages();
$assert(array(
    'button_label_wrapper',
    'button_control_wrapper',
    'accessible_link_content_wrapper',
    'button_label_span',
    'navigation_carrier_layout_wrapper',
    'flattened_navigation_wrapper',
    'inert_navigation_support_wrapper',
    'media_text_media_wrapper',
    'media_text_text_side_group',
    'captured_menu_trigger_group',
    'capture_close_helper',
    'equivalent_navigation_duplicate',
    'carousel_stage_wrapper',
    'retained_wrapper_hook',
) === array_keys($tables), 'pattern-tables');
foreach ( $tables as $table => $guards ) {
    $assert(array() === array_diff(array_unique(array_values($guards)), WrapperDecision::STAGES), $table . '-guards-belong-to-known-stages');
}

$never = static fn (): bool => throw new LogicException('guard evaluated after an earlier match');
$yes = static fn (): bool => true;
$no = static fn (): bool => false;
$html = static function (string $markup): DOMDocument {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<html><body>' . $markup . '</body></html>');
    libxml_clear_errors();
    return $doc;
};

// Button label wrapper paint.
$d = WrapperDecision::buttonLabelWrapper('background-color: #f00');
$assert($d->isPreserve() && 'paints_background' === $d->reason, 'label-background', $d->reason);
$assert('paints_border' === WrapperDecision::buttonLabelWrapper('border: 1px solid red')->reason, 'label-border');
$assert('paints_box_shadow' === WrapperDecision::buttonLabelWrapper('box-shadow: 0 0 2px red')->reason, 'label-shadow');
$assert('paints_outline' === WrapperDecision::buttonLabelWrapper('outline: 2px solid red')->reason, 'label-outline');
$d = WrapperDecision::buttonLabelWrapper('border: 0; box-shadow: none; color: red');
$assert($d->isFold() && 'paint_free_label_wrapper' === $d->reason, 'label-fold', $d->reason);

// Button control wrapper.
$doc = $html('<div id="r" role="BUTTON"><a>x</a></div><div id="p"><a>x</a></div>');
$d = WrapperDecision::buttonControlWrapper($doc->getElementById('r'), $never);
$assert(null !== $d && $d->isFold() && 'button_role' === $d->reason, 'control-role', (string) $d?->reason);
$assert('wrapper_button_style_signal' === WrapperDecision::buttonControlWrapper($doc->getElementById('p'), $yes)?->reason, 'control-style');
$assert(null === WrapperDecision::buttonControlWrapper($doc->getElementById('p'), $no), 'control-none');

// Accessible link content wrapper.
$doc = $html('<a id="a1"> <span class="label">Go</span> </a><a id="a2"><span>Go</span><span>x</span></a><a id="a3"><b class="x">Go</b></a><a id="a4"><span>Go</span></a><a id="a5"><span onclick="x">Go</span></a><a id="a6">t<span class="x">Go</span></a>');
$content = static fn (string $name): bool => 'class' === $name;
$r = WrapperDecision::accessibleLinkContentWrapper($doc->getElementById('a1'), $content);
$assert($r['disposition']->isPreserve() && 'restated_on_rich_text_element' === $r['disposition']->reason && 'span' === $r['wrapper']?->tagName, 'link-wrapper-restated', $r['disposition']->reason);
foreach ( array( 'a2' => 'not_single_child_element', 'a3' => 'not_span_or_div', 'a4' => 'no_attributes', 'a5' => 'non_content_attribute', 'a6' => 'not_single_child_element' ) as $id => $reason ) {
    $r = WrapperDecision::accessibleLinkContentWrapper($doc->getElementById($id), $content);
    $assert($r['disposition']->isFold() && $reason === $r['disposition']->reason && null === $r['wrapper'], 'link-wrapper-' . $id, $r['disposition']->reason);
}

// Button label span chain.
$assert('no_wrapping_span' === WrapperDecision::buttonLabelSpan(null, $never, $never)->reason, 'span-none');
$assert('bare_span_not_restorable' === WrapperDecision::buttonLabelSpan(false, $never, $never)->reason, 'span-bare');
$d = WrapperDecision::buttonLabelSpan(true, $yes, $never);
$assert($d->isPreserve() && 'retained_source_element' === $d->reason, 'span-retained', $d->reason);
$assert('source_item_sequence' === WrapperDecision::buttonLabelSpan(true, $no, $yes)->reason, 'span-sequence');
$d = WrapperDecision::buttonLabelSpan(true, $no, $no);
$assert($d->isFold() && 'presentational_label_span' === $d->reason, 'span-fold', $d->reason);

// Navigation carrier layout wrapper.
$doc = $html('<nav id="n1"><!-- c --> <div id="row"><a>a</a><a>b</a></div></nav><nav id="n2"><div></div><div></div></nav><nav id="n3"><ul><li>a</li><li>b</li></ul></nav><nav id="n4"><div><a>a</a></div></nav><nav id="n5">text<div><a>a</a><a>b</a></div></nav>');
$chromeNo = static fn (DOMElement $wrapper): bool => false;
$r = WrapperDecision::navigationCarrierLayoutWrapper($doc->getElementById('n1'), $chromeNo);
$assert($r['disposition']->isPreserve() && 'carrier_layout_row_box' === $r['disposition']->reason && $doc->getElementById('row') === $r['wrapper'], 'carrier-row', $r['disposition']->reason);
$r = WrapperDecision::navigationCarrierLayoutWrapper($doc->getElementById('n1'), static fn (DOMElement $wrapper): bool => true);
$assert('navigation_chrome' === $r['disposition']->reason && null === $r['wrapper'], 'carrier-chrome', $r['disposition']->reason);
foreach ( array( 'n2' => 'not_single_child_element', 'n3' => 'not_div', 'n4' => 'fewer_than_two_element_children', 'n5' => 'not_single_child_element' ) as $id => $reason ) {
    $r = WrapperDecision::navigationCarrierLayoutWrapper($doc->getElementById($id), $chromeNo);
    $assert($r['disposition']->isFold() && $reason === $r['disposition']->reason, 'carrier-' . $id, $r['disposition']->reason);
}

// Flattened navigation wrapper visibility.
$neverArray = static fn (): array => throw new LogicException('declarations read after an earlier match');
$neverClass = static fn (): string => throw new LogicException('carrier class recorded after an earlier match');
$distributable = static fn (string $property, array $values): bool => 'background' !== $property;
$assert('no_navigation_context' === WrapperDecision::flattenedNavigationWrapper(false, $never, $neverArray, $distributable, $neverClass)['disposition']->reason, 'flatten-no-context');
$assert('display_none_at_every_viewport' === WrapperDecision::flattenedNavigationWrapper(true, $yes, $neverArray, $distributable, $neverClass)['disposition']->reason, 'flatten-hidden-everywhere');
$assert('not_hidden_at_any_viewport' === WrapperDecision::flattenedNavigationWrapper(true, $no, static fn (): array => array( 'display' => array( 'flex' ) ), $distributable, $neverClass)['disposition']->reason, 'flatten-visible');
$reads = 0;
$declared = static function () use (&$reads): array {
    ++$reads;
    return array( 'display' => array( 'none !important' ), 'background' => array( 'red' ) );
};
$assert('undistributable_declaration' === WrapperDecision::flattenedNavigationWrapper(true, $no, $declared, $distributable, $neverClass)['disposition']->reason && 1 === $reads, 'flatten-undistributable');
$hidden = static fn (): array => array( 'display' => array( 'none' ), 'gap' => array( '1rem' ) );
$r = WrapperDecision::flattenedNavigationWrapper(true, $no, $hidden, $distributable, static fn (): string => '');
$assert('no_carrier_class' === $r['disposition']->reason && '' === $r['className'], 'flatten-no-class');
$r = WrapperDecision::flattenedNavigationWrapper(true, $no, $hidden, $distributable, static fn (): string => 'carrier-1');
$assert($r['disposition']->isFold() && 'visibility_restated_per_item' === $r['disposition']->reason && 'carrier-1' === $r['className'], 'flatten-restated', $r['disposition']->reason);

// Inert navigation support wrapper.
$doc = $html('<p id="p"><i></i></p><div id="t">text</div><span id="s"><i aria-hidden="true"></i></span>');
$assert('not_div_or_span' === WrapperDecision::inertNavigationSupportWrapper($doc->getElementById('p'), $never)->reason, 'support-tag');
$assert('text_leaf' === WrapperDecision::inertNavigationSupportWrapper($doc->getElementById('t'), $never)->reason, 'support-text');
$d = WrapperDecision::inertNavigationSupportWrapper($doc->getElementById('s'), $yes);
$assert($d->isDrop() && 'inert_support_descendants' === $d->reason, 'support-drop', $d->reason);
$d = WrapperDecision::inertNavigationSupportWrapper($doc->getElementById('s'), $no);
$assert($d->isPreserve() && 'navigable_or_named_descendant' === $d->reason, 'support-keep', $d->reason);

// Media/text wrappers.
$assert('decorated_media_wrapper' === WrapperDecision::mediaTextMediaWrapper(array( 'border-radius' => '4px' ))->reason, 'media-border');
$assert('decorated_media_wrapper' === WrapperDecision::mediaTextMediaWrapper(array( 'overflow' => 'hidden' ))->reason, 'media-overflow');
$assert('media_wrapper_replaced_by_figure' === WrapperDecision::mediaTextMediaWrapper(array( 'width' => '50%', 'borderless' => 'x' ))->reason, 'media-fold');
$doc = $html('<div id="side" class="blocks-engine-css-owned-a"></div>');
$side = $doc->getElementById('side');
$assert('no_group_attributes' === WrapperDecision::mediaTextTextSideGroup(array(), $side)->reason, 'text-side-empty');
$assert('attributes_beyond_class_name' === WrapperDecision::mediaTextTextSideGroup(array( 'className' => 'x', 'tagName' => 'div' ), $side)->reason, 'text-side-attrs');
$assert('authored_class' === WrapperDecision::mediaTextTextSideGroup(array( 'className' => 'blocks-engine-css-owned-a' ), $side)->reason, 'text-side-authored-marker');
$assert('authored_class' === WrapperDecision::mediaTextTextSideGroup(array( 'className' => 'card' ), $side)->reason, 'text-side-class');
$d = WrapperDecision::mediaTextTextSideGroup(array( 'className' => 'blocks-engine-css-owned-b' ), $side);
$assert($d->isFold() && 'generated_layout_markers_only' === $d->reason, 'text-side-hoisted', $d->reason);

// Captured menu trigger group.
$doc = $html('<div id="row"><a>a</a></div><div id="g"><button id="t1">menu</button><button id="t2">x</button></div><button id="t3">y</button><dialog id="d"></dialog>');
$row = $doc->getElementById('row');
$group = $doc->getElementById('g');
$dialog = $doc->getElementById('d');
$t1 = $doc->getElementById('t1');
$assert('trigger_is_its_own_group' === WrapperDecision::capturedMenuTriggerGroup($t1, array( $t1 ), $dialog, $row, $never, $never)->reason, 'trigger-own');
$assert('group_holds_dialog' === WrapperDecision::capturedMenuTriggerGroup($doc->getElementsByTagName('body')->item(0), array( $t1 ), $dialog, $row, $never, $never)->reason, 'trigger-holds-dialog');
$outer = $doc->createElement('div');
$row->parentNode->insertBefore($outer, $row);
$outer->appendChild($row);
$outer->appendChild($group);
$assert('group_holds_row' === WrapperDecision::capturedMenuTriggerGroup($outer, array( $t1 ), $dialog, $row, $never, $never)->reason, 'trigger-holds-row');
$assert('trigger_outside_group' === WrapperDecision::capturedMenuTriggerGroup($group, array( $t1, $doc->getElementById('t3') ), $dialog, $row, $never, $never)->reason, 'trigger-outside');
$assert('unrepeated_control' === WrapperDecision::capturedMenuTriggerGroup($group, array( $t1 ), $dialog, $row, $yes, $never)->reason, 'trigger-unrepeated');
$assert('non_punctuation_text' === WrapperDecision::capturedMenuTriggerGroup($group, array( $t1 ), $dialog, $row, $no, $no)->reason, 'trigger-text');
$d = WrapperDecision::capturedMenuTriggerGroup($group, array( $t1, $doc->getElementById('t2') ), $dialog, $row, $no, $yes);
$assert($d->isDrop() && 'trigger_group_repeats_row' === $d->reason, 'trigger-drop', $d->reason);

// Capture close helpers.
$doc = $html('<div id="panel"><button id="in" hidden></button></div><button id="out" hidden></button><button id="shown"></button><button id="other" hidden></button>');
$panel = $doc->getElementById('panel');
$closeAttr = Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence::DIALOG_CLOSE;
foreach ( array( 'in', 'out', 'shown', 'other' ) as $id ) {
    $doc->getElementById($id)->setAttribute($closeAttr, 'other' === $id ? 'p2' : 'p1');
}
$d = WrapperDecision::captureCloseHelper($doc->getElementById('out'), 'p1', $panel);
$assert($d->isDrop() && 'superseded_capture_close_helper' === $d->reason, 'close-drop', $d->reason);
$assert('inside_panel' === WrapperDecision::captureCloseHelper($doc->getElementById('in'), 'p1', $panel)->reason, 'close-inside');
$assert('not_hidden' === WrapperDecision::captureCloseHelper($doc->getElementById('shown'), 'p1', $panel)->reason, 'close-shown');
$assert('not_close_helper_for_panel' === WrapperDecision::captureCloseHelper($doc->getElementById('other'), 'p1', $panel)->reason, 'close-other');

// Equivalent navigation duplicates.
$doc = $html('<nav id="n"><a>a</a></nav><div id="c"><button id="ctl"></button></div><nav id="dup"><a>a</a></nav>');
$nav = $doc->getElementById('n');
$ctl = $doc->getElementById('ctl');
$dup = $doc->getElementById('dup');
$assert('not_element' === WrapperDecision::equivalentNavigationDuplicate($doc->createTextNode('x'), $nav, $ctl, $never, $never, $never)->reason, 'dup-text');
$assert('projected_navigation' === WrapperDecision::equivalentNavigationDuplicate($nav, $nav, $ctl, $never, $never, $never)->reason, 'dup-self');
$assert('already_suppressed' === WrapperDecision::equivalentNavigationDuplicate($dup, $nav, $ctl, $yes, $never, $never)->reason, 'dup-suppressed');
$assert('holds_menu_control' === WrapperDecision::equivalentNavigationDuplicate($doc->getElementById('c'), $nav, $ctl, $no, $never, $never)->reason, 'dup-control');
$assert('not_navigation_target' === WrapperDecision::equivalentNavigationDuplicate($dup, $nav, $ctl, $no, $no, $never)->reason, 'dup-target');
$assert('different_navigation_signature' === WrapperDecision::equivalentNavigationDuplicate($dup, $nav, $ctl, $no, $yes, $no)->reason, 'dup-signature');
$d = WrapperDecision::equivalentNavigationDuplicate($dup, $nav, $ctl, $no, $yes, $yes);
$assert($d->isDrop() && 'equivalent_navigation_duplicate' === $d->reason, 'dup-drop', $d->reason);

// Carousel stage wrappers.
$assert('stage_class' === WrapperDecision::carouselStageWrapper('track', array( 'id' => 'x' ), 'width:1px')->reason, 'stage-class-first');
$assert('stage_identity_attributes' === WrapperDecision::carouselStageWrapper('', array( 'id' => 'x' ), '')->reason, 'stage-attributes');
$assert('stage_geometry_style' === WrapperDecision::carouselStageWrapper('', array(), 'width:1px')->reason, 'stage-style');
$d = WrapperDecision::carouselStageWrapper('', array(), '');
$assert($d->isFold() && 'no_stage_identity_or_geometry' === $d->reason, 'stage-fold', $d->reason);
$assert(WrapperDecision::carouselStageWrapperAttribute('aria-label', 'Slides'), 'stage-attr-aria');
$assert(! WrapperDecision::carouselStageWrapperAttribute('data-wp-bind', 'x'), 'stage-attr-wp');
$assert(! WrapperDecision::carouselStageWrapperAttribute('data-controller', 'x'), 'stage-attr-controller');
$assert(! WrapperDecision::carouselStageWrapperAttribute('class', 'x'), 'stage-attr-class');
$assert(! WrapperDecision::carouselStageWrapperAttribute('id', str_repeat('x', 513)), 'stage-attr-long');

// Retained custom-wrapper hooks.
$doc = $html('<x-hook id="h"> <div id="only"></div> </x-hook><x-hook id="two"><i></i><b></b></x-hook><x-hook id="text">t<i></i></x-hook>');
$hook = $doc->getElementById('h');
$r = WrapperDecision::retainedWrapperHook($hook, false, $never, $never);
$assert(null === $r['disposition'] && null === $r['child'], 'hook-not-retained');
$r = WrapperDecision::retainedWrapperHook($hook, true, $yes, $yes);
$assert(null !== $r['disposition'] && $r['disposition']->isFold() && 'boxless_retained_hook' === $r['disposition']->reason && $doc->getElementById('only') === $r['child'], 'hook-fold');
$assert('unsafe_transparent_custom_element' === WrapperDecision::retainedWrapperHook($hook, true, $no, $never)['disposition']?->reason, 'hook-unsafe');
$assert('hook_has_box' === WrapperDecision::retainedWrapperHook($hook, true, $yes, $no)['disposition']?->reason, 'hook-box');
$assert('not_single_child_element' === WrapperDecision::retainedWrapperHook($doc->getElementById('two'), true, $never, $never)['disposition']?->reason, 'hook-two');
$assert('not_single_child_element' === WrapperDecision::retainedWrapperHook($doc->getElementById('text'), true, $never, $never)['disposition']?->reason, 'hook-text');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Wrapper decision pattern tests: ' . $assertions . " passed\n";

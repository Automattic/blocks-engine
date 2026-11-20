<?php
declare(strict_types=1);

/**
 * WrapperDecision (#7471, stage 1 slice 4) owns the custom-wrapper
 * convention, render-neutral, transparent-unknown, unproven custom element,
 * custom image host, empty feature shell, inline text wrapper, figure
 * caption and anchor wrapper decisions. This pins the inventory (names,
 * stages, order) and the closure-driven tables' outcomes and short-circuits.
 * Output parity is pinned by the transform tests named in
 * docs/wrapper-decisions.md.
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

$tables = WrapperDecision::conversionGuardStages();
$assert(array(
    'convention_tag',
    'convention_instance',
    'render_neutral_wrapper',
    'render_neutral_custom_flow_wrapper',
    'transparent_unknown_element',
    'unproven_custom_element',
    'picture_box',
    'image_host_identity',
    'empty_interactive_feature_shell',
    'inline_text_wrapper',
    'figure_caption',
    'non_rendering_anchor',
    'link_wrapper',
) === array_keys($tables), 'conversion-tables');
foreach ( $tables as $table => $guards ) {
    $assert(array() === array_diff(array_unique(array_values($guards)), WrapperDecision::STAGES), $table . '-guards-belong-to-known-stages');
}

$never = static fn (): bool => throw new LogicException('guard evaluated after an earlier match');
$yes = static fn (): bool => true;
$no = static fn (): bool => false;

// Convention tag: instance count, then tag CSS, then content shapes.
$d = WrapperDecision::conventionTag(2, 3, $never, $never);
$assert($d->isPreserve() && 'too_few_instances' === $d->reason, 'convention-too-few', $d->reason);
$d = WrapperDecision::conventionTag(3, 3, $no, $never);
$assert($d->isPreserve() && 'tag_css_not_render_neutral' === $d->reason && WrapperDecision::STAGE_GEOMETRY === $d->stage, 'convention-css', $d->reason);
$d = WrapperDecision::conventionTag(3, 3, $yes, $no);
$assert($d->isPreserve() && 'uniform_content_shape' === $d->reason, 'convention-component', $d->reason);
$assert(WrapperDecision::conventionTag(4, 3, $yes, $yes)->isFold(), 'convention-fold');

// Convention instance: non-neutral instances stay without asking about selectors.
$d = WrapperDecision::conventionInstance($no, $never);
$assert($d->isPreserve() && 'not_render_neutral' === $d->reason, 'instance-not-neutral', $d->reason);
$d = WrapperDecision::conventionInstance($yes, $yes);
$assert($d->isPreserve() && 'author_attribute_selector_hook' === $d->reason && WrapperDecision::STAGE_RESTATEMENT === $d->stage, 'instance-hook', $d->reason);
$assert(WrapperDecision::conventionInstance($yes, $no)->isFold(), 'instance-fold');

// Unproven custom element.
$d = WrapperDecision::unprovenCustomElement($yes, $yes, $never);
$assert($d->isDrop() && 'empty_render_neutral_configured_host' === $d->reason, 'unproven-omit', $d->reason);
$d = WrapperDecision::unprovenCustomElement($yes, $no, $never);
$assert($d->isPreserve() && 'paint_free_runtime_placeholder' === $d->reason, 'unproven-spacer', $d->reason);
$d = WrapperDecision::unprovenCustomElement($no, $never, $yes);
$assert($d->isDrop() && 'display_none_at_every_viewport' === $d->reason, 'unproven-hidden', $d->reason);
$d = WrapperDecision::unprovenCustomElement($no, $never, $no);
$assert($d->isDrop() && 'unproven_runtime_dependency' === $d->reason && WrapperDecision::STAGE_IDENTITY === $d->stage, 'unproven-finding', $d->reason);

// Picture box and image host identity over real elements.
$doc = new DOMDocument();
$doc->loadHTML('<div id="host" data-a="1"><picture id="p1"><img></picture><picture data-x="1" class="c"><img></picture><picture title="t"><img></picture></div>', LIBXML_NOERROR);
$pictures = $doc->getElementsByTagName('picture');
$d = WrapperDecision::pictureBox($pictures->item(0), $never, $never, $never);
$assert($d->isPreserve() && 'has_id_attribute' === $d->reason, 'picture-id', $d->reason);
$d = WrapperDecision::pictureBox($pictures->item(1), static fn (string $name): bool => 'data-x' === $name, $never, $never);
$assert($d->isPreserve() && 'referenced_data_attribute' === $d->reason, 'picture-referenced-data', $d->reason);
$d = WrapperDecision::pictureBox($pictures->item(2), $never, $never, $never);
$assert($d->isPreserve() && 'non_data_attribute' === $d->reason, 'picture-non-data', $d->reason);
$d = WrapperDecision::pictureBox($pictures->item(1), static fn (string $name): bool => false, $no, $never);
$assert($d->isPreserve() && 'not_render_neutral' === $d->reason, 'picture-not-neutral', $d->reason);
$d = WrapperDecision::pictureBox($pictures->item(1), static fn (string $name): bool => false, $yes, $yes);
$assert($d->isPreserve() && 'selector_reaches_image_through_picture' === $d->reason, 'picture-selector', $d->reason);
$assert(WrapperDecision::pictureBox($pictures->item(1), static fn (string $name): bool => false, $yes, $no)->isCoalesce(), 'picture-coalesce');

$host = $doc->getElementById('host');
$d = WrapperDecision::imageHostIdentity($host, $never, $never, $never, $never);
$assert(null !== $d && 'non_data_attribute' === $d->reason, 'host-id-attribute', (string) $d?->reason);
$host->removeAttribute('id');
$d = WrapperDecision::imageHostIdentity($host, $no, static fn (string $name): bool => false, $no, $yes);
$assert(null !== $d && 'semantic_selector_marker' === $d->reason, 'host-semantic-marker', (string) $d?->reason);
$assert(null === WrapperDecision::imageHostIdentity($host, $no, static fn (string $name): bool => false, $no, $no), 'host-inert');

// Inline text wrapper tables.
$span = $doc->createElement('span');
$section = $doc->createElement('section');
$assert('not_div_or_span' === WrapperDecision::visualTextWrapperCandidate($section, $never, $never, $never)?->reason, 'text-wrapper-tag');
$assert('semantic_marked_child' === WrapperDecision::visualTextWrapperCandidate($span, $no, $yes, $never)?->reason, 'text-wrapper-marked-child');
$assert(null === WrapperDecision::visualTextWrapperCandidate($span, $no, $no, $no), 'text-wrapper-candidate');
$assert('inline_empty_visual_child' === WrapperDecision::inlineTextWrapperEmptyVisualChild($yes)?->reason, 'text-wrapper-empty-visual-child');
$assert(null === WrapperDecision::inlineTextWrapperEmptyVisualChild($no), 'text-wrapper-no-empty-visual-child');
$assert(WrapperDecision::visualTextWrapperFold($yes)->isFold(), 'text-wrapper-fold');
$assert('no_visual_text_wrapper_signal' === WrapperDecision::visualTextWrapperFold($no)->reason, 'text-wrapper-no-signal');

// Figure caption.
$neverDisposition = static fn (): WrapperDisposition => throw new LogicException('empty visual read for a visible caption');
$assert('caption_text' === WrapperDecision::figureCaption(true, $neverDisposition)->reason, 'caption-text');
$spacer = WrapperDisposition::preserve('block_identity_or_box', WrapperDecision::STAGE_IDENTITY);
$assert($spacer === WrapperDecision::figureCaption(false, static fn (): WrapperDisposition => $spacer), 'caption-empty-visual');

// Anchors.
$doc->loadHTML('<a class="x"><span></span><div></div></a><a class="y"><img src="a.png"></a><a class="z">text<span></span></a>', LIBXML_NOERROR);
$anchors = $doc->getElementsByTagName('a');
$emptyAnchor = static fn (DOMElement $a): bool => ! $a->hasAttribute('href');
$drop = static fn (DOMElement $e): WrapperDisposition => WrapperDisposition::drop('has_rendered_text');
$d = WrapperDecision::nonRenderingAnchor($anchors->item(0), $emptyAnchor, $drop);
$assert($d->isDrop() && 'non_rendering_non_navigating_anchor' === $d->reason, 'anchor-non-rendering', $d->reason);
$d = WrapperDecision::nonRenderingAnchor($anchors->item(0), $emptyAnchor, static fn (DOMElement $e): WrapperDisposition => WrapperDisposition::preserve('inline_empty_visual'));
$assert($d->isPreserve() && 'empty_visual_descendant' === $d->reason, 'anchor-empty-visual-descendant', $d->reason);
$d = WrapperDecision::nonRenderingAnchor($anchors->item(1), $emptyAnchor, $drop);
$assert($d->isPreserve() && 'sourced_image_descendant' === $d->reason, 'anchor-sourced-image', $d->reason);
$d = WrapperDecision::nonRenderingAnchor($anchors->item(2), $emptyAnchor, $drop);
$assert($d->isPreserve() && 'has_text' === $d->reason, 'anchor-text', $d->reason);

$doc->loadHTML('<a href="/x"><h3>t</h3><button>b</button></a>', LIBXML_NOERROR);
$card = $doc->getElementsByTagName('a')->item(0);
$d = WrapperDecision::linkWrapper($card, $never);
$assert($d->isPreserve() && 'interactive_descendant' === $d->reason, 'link-interactive', $d->reason);
$card->removeChild($doc->getElementsByTagName('button')->item(0));
$assert(WrapperDecision::linkWrapper($card, $yes)->isFold(), 'link-stretched');
$assert('not_stretched_link_card' === WrapperDecision::linkWrapper($card, $no)->reason, 'link-not-stretched');
$assert(null === WrapperDecision::linkWrapperPropagation(true, true, $never), 'link-propagation-stretched');
$assert(null === WrapperDecision::linkWrapperPropagation(false, false, $never), 'link-propagation-no-link');
$assert('link_restated_on_children' === WrapperDecision::linkWrapperPropagation(false, true, $yes)?->reason, 'link-propagated');
$assert('link_wrapper_dropped' === WrapperDecision::linkWrapperPropagation(false, true, $no)?->reason, 'link-dropped');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Wrapper decision conversion tests: ' . $assertions . " passed\n";

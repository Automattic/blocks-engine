<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * The declared, ordered cascade layers for the engine-support stylesheet
 * emitted after the author stylesheet (`engine-support-after-author`; see
 * {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation::materializeAuthorStylesheet()}).
 *
 * Precedence used to be the line order in which that method called its
 * emitters — nineteen interleaved appends across eight collaborators, with
 * no record of why any one call sat where it did. That is now this list:
 * every contribution is tagged with one of these cases, and
 * {@see LayeredCssCollector} assembles the stylesheet by a stable sort on
 * the case's declared rank. To change precedence, reorder or insert a case
 * here — never a method call.
 *
 * Case order below is rank-ascending, i.e. the compiled emission order. Two
 * cases both being "about" the same kind of fix does not make them
 * interchangeable: where a case sits encodes a real precedence requirement
 * (documented on the case), and merging two non-adjacent cases into one
 * would silently reorder their contributions relative to whatever sits
 * between them today.
 */
enum CascadeLayer: int
{
    /**
     * Structural repairs to markup Gutenberg itself generates — not
     * anything the author authored — that must land the moment the author
     * cascade begins, at native selector specificity, so an authored rule
     * for the same element still wins over it.
     */
    case GENERATED_MARKUP_REPAIR = 10;

    /**
     * Author-authored visual facts captured during translation (resting
     * link/icon colors, arbitrary source-selector-to-target-selector
     * declarations) restated on the native element core actually renders,
     * because core's own generated markup does not carry them.
     */
    case SOURCE_STYLE_PROJECTION = 20;

    /**
     * Repairs to how one specific core block renders its own markup,
     * independent of anything captured from the source document — e.g.
     * suppressing core/social-links' built-in icon glyphs so the source's
     * icon-font glyphs are not painted twice.
     */
    case BLOCK_RENDER_REPAIR = 30;

    /**
     * Captured per-component styling (disclosure/accordion control
     * presentation, navigation/button wrapper spacing, header anchors and
     * rich text) restated onto the specific descendant element core
     * generates to hold it.
     */
    case COMPONENT_STYLE_RESTATEMENT = 40;

    /**
     * Offsets for authored viewport-anchored (fixed/sticky) elements so
     * they clear the WordPress admin-bar chrome the source document never
     * had to design around. Depends on the author stylesheet already being
     * known (its declarations are what get scanned for `position`/`top`),
     * so it cannot land any earlier than this.
     */
    case VIEWPORT_CHROME_COMPAT = 50;

    /**
     * The repair set for a source list promoted to core/navigation:
     * responsive host display, inline auto-margins, padding, and
     * re-pointing authored menu-item rules from the source anchor onto the
     * anchor core renders. Kept as one layer because these contributions
     * only make sense relative to each other — padding before item
     * anchors, item anchors before the overlay/overflow follow-ups — and
     * are gated on the same `blocks-engine-list-navigation` marker.
     */
    case LIST_NAVIGATION_REPAIR = 60;

    /**
     * A second, later wave of block-render repairs (inline-navigation
     * display, social-links logo-only styling, source social-item spacing)
     * that must follow LIST_NAVIGATION_REPAIR.
     */
    case SECONDARY_BLOCK_RENDER_REPAIR = 70;

    /**
     * Interaction-state (current/hover/focus) colour repairs for
     * navigation items. Must follow LIST_NAVIGATION_REPAIR: it proves each
     * source-cascade winner against the resting-state item-anchor rules
     * that layer already emitted before it increases specificity for the
     * state.
     */
    case NAVIGATION_STATE_REPAIR = 80;

    /**
     * Colour and display repairs specific to a navigation that was not
     * promoted to a list (a "direct" navigation).
     */
    case DIRECT_NAVIGATION_REPAIR = 90;

    /** Repairs specific to native/direct-flex/width-carrying button markup. */
    case BUTTON_REPAIR = 100;

    /**
     * Repaints an element the editor had frozen in a hidden authoring state
     * (e.g. a closed disclosure/accordion) back to its intended resting
     * appearance.
     */
    case CLOSED_STATE_REPAIR = 110;

    /**
     * Settles a captured entrance/reveal animation that lost its driver on
     * import to the resolved appearance it was travelling towards, instead
     * of the hidden keyframe it starts from (#239). Reads the projected
     * author CSS, so it must run last: it needs every earlier layer's
     * view of the final author-CSS shape.
     */
    case REVEAL_SETTLE = 120;
}

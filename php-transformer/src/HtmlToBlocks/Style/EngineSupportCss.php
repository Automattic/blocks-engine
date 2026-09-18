<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;

/**
 * Marker-class engine-support CSS gated on serialized block markup.
 *
 * Extracted from {@see HtmlCompilation::materializeAuthorStylesheet()}. No
 * HtmlCompilation `$this` — only public constants and the layout-shell block
 * name passed in as a string. The after-author methods tag their own
 * {@see CascadeRule} layer, per {@see CascadeLayer}; the compiler only
 * collects and orders what it is handed, it does not decide where a
 * contribution belongs.
 */
final class EngineSupportCss
{
    private const BACKGROUND_IMAGE_CLASS = 'blocks-engine-background-image';

    private const BACKGROUND_IMAGE_SCALE_CLASS_PREFIX = 'blocks-engine-background-image-';

    private const EMPTY_FLEX_ITEM_CLASS = 'blocks-engine-empty-flex-item';

    private const EMPTY_FLEX_ITEM_COLUMN_CLASS = 'blocks-engine-empty-flex-column-item';

    private const LAYOUT_TABLE_COLUMNS_CLASS = 'blocks-engine-layout-table-columns';

    private const PROPAGATED_LINK_COLOR_CARRIER_CLASS = 'blocks-engine-propagated-link-color';

    private const CSS_OWNED_LAYOUT_CLASS = 'blocks-engine-css-owned-layout';

    private const CSS_OWNED_FLOW_CLASS = 'blocks-engine-css-owned-flow';

    private const CSS_OWNED_GRID_CLASS = 'blocks-engine-css-owned-grid';

    private const LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS = 'blocks-engine-layout-shell-editor-inner-blocks';

    /**
     * @return list<string>
     */
    public function beforeAuthorCss(string $serializedBlocks, string $layoutShellBlockName): array
    {
        $parts = array();
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS) ) {
            // A paragraph is required for valid block markup, but phrasing content
            // did not have paragraph margins in the source document.
            $parts[] = ':root :where(.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . '){margin-top:0;margin-bottom:0}'
                . "\n" . ':root :where(p.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . '.has-text-color)>a{color:inherit}'
                . "\n" . ':where(p.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . ')>a{text-decoration:underline}'
                . "\n" . ':where(p.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . '.' . SourceBlockAttributeProjector::SYNTHETIC_ANCHOR_UNDECORATED_CLASS . ')>a{text-decoration:none}'
                // A source anchor blockified by a flex/grid parent resolved to
                // display:block with no authored selector of its own to carry
                // that onto the carrier's <a> -- the UA default `inline` would
                // otherwise win and shrink its line height (see
                // SourceBlockAttributeProjector::sourceAnchorResolvesToBlockDisplay()).
                . "\n" . ':where(p.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . '.' . SourceBlockAttributeProjector::SYNTHETIC_ANCHOR_BLOCK_DISPLAY_CLASS . ')>a{display:block}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::SYNTHETIC_EMBED_FIGURE_CLASS) ) {
            // core/embed's save() always wraps its content in a <figure> the
            // source never had — a WordPress block-structure artifact, not an
            // authored wrapper. That figure's own default margin therefore has
            // no source counterpart. Zero specificity, exactly like the
            // synthetic-paragraph reset above: a genuinely authored margin
            // reaching this same figure through the ordinary author-stylesheet
            // projection is already reset-resistant (boosted above the
            // block-library default it must beat) and so still outranks this.
            $parts[] = ':root :where(.' . SourceBlockAttributeProjector::SYNTHETIC_EMBED_FIGURE_CLASS . '){margin-top:0;margin-bottom:0}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::SYNTHETIC_SVG_PARAGRAPH_CLASS) ) {
            // A standalone SVG becomes valid RichText image markup inside a
            // paragraph. Its source was a block box, so remove the paragraph's
            // otherwise-added line box without affecting inline SVG text.
            $parts[] = ':root p.' . SourceBlockAttributeProjector::SYNTHETIC_SVG_PARAGRAPH_CLASS . '{line-height:0!important}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::HIDDEN_RICH_TEXT_MARKER_CLASS) ) {
            $parts[] = ':root :where(.' . SourceBlockAttributeProjector::HIDDEN_RICH_TEXT_MARKER_CLASS . '){display:none!important}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::SYNTHETIC_IMAGE_FIGURE_CLASS) ) {
            $parts[] = '.' . SourceBlockAttributeProjector::SYNTHETIC_IMAGE_FIGURE_CLASS . '{margin:0}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS) ) {
            $parts[] = ':root .' . SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS . '{display:inline-block}';
        }
        if ( str_contains($serializedBlocks, self::BACKGROUND_IMAGE_CLASS) ) {
            // The source painted this image as a background, where the element's
            // own box decides the size and the image never overflows it. core's
            // scale attribute only reaches the image when width and height are
            // saved, so the sized cases carry that contract here instead.
            $parts[] = ':root :where(.' . self::BACKGROUND_IMAGE_CLASS . ') img{max-width:100%}'
                . "\n" . ':root :where(.' . self::BACKGROUND_IMAGE_SCALE_CLASS_PREFIX . 'cover,.' . self::BACKGROUND_IMAGE_SCALE_CLASS_PREFIX . 'contain){height:100%}'
                . "\n" . ':root :where(.' . self::BACKGROUND_IMAGE_SCALE_CLASS_PREFIX . 'cover) img{width:100%;height:100%;object-fit:cover}'
                . "\n" . ':root :where(.' . self::BACKGROUND_IMAGE_SCALE_CLASS_PREFIX . 'contain) img{width:100%;height:100%;object-fit:contain}';
        }
        if ( str_contains($serializedBlocks, AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS) ) {
            $parts[] = ':where(p.' . AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS . '){display:contents;margin:0!important;padding:0!important;border:0!important}';
            // An anchor inside the carrier exists only because a content-wrapping
            // link was pushed down onto it. It has no presentation of its own,
            // but as a block box it establishes a line box from the inherited
            // font, so text the source sized smaller than its surroundings is
            // measured against that instead of its own line height. A stacked
            // brand lockup grew by the difference on every line.
            $parts[] = ':where(p.' . AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS . '>a){display:contents}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_LAYOUT_CLASS) ) {
            // Gutenberg inserts two editor-only InnerBlocks wrappers between a
            // core Group and its children. Keep authored grid/flex children as
            // direct layout items, matching the saved frontend markup.
            $parts[] = ':root :where(.' . self::CSS_OWNED_LAYOUT_CLASS . ')>.block-editor-inner-blocks,'
                . ':root :where(.' . self::CSS_OWNED_LAYOUT_CLASS . ')>.block-editor-inner-blocks>.block-editor-block-list__layout{display:contents}'
                // Empty Group placeholders have an additional unadorned editor wrapper.
                . ':root .editor-styles-wrapper :where(.' . self::CSS_OWNED_LAYOUT_CLASS . ')>div:not([class]):not([id]):not([style]):has(>[data-block].wp-block-group__placeholder){display:contents}';
        }
        if ( str_contains($serializedBlocks, '<!-- wp:' . $layoutShellBlockName) ) {
            // A layout shell preserves the source wrapper chain. Gutenberg's
            // InnerBlocks wrappers live below that chain, not directly below the
            // shell root. Target the explicit owning-layer marker so their boxes
            // cannot become an absolute-position containing block or change the
            // authored sibling topology. Do not flatten nested native blocks.
            $layoutShellClass = 'wp-block-' . str_replace('/', '-', $layoutShellBlockName);
            $parts[] = ':root :where(.' . $layoutShellClass . ') .' . self::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS . '{display:contents}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_FLOW_CLASS) ) {
            $parts[] = ':root :where(.' . self::CSS_OWNED_FLOW_CLASS . '>p){margin-top:0;margin-bottom:0}';
        }
        if ( str_contains($serializedBlocks, ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS) ) {
            // Positioned fragment links retain their source anchor and selectors;
            // their valid paragraph host must not create a line box in document flow.
            $parts[] = ':where(.' . ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS . '){display:contents!important}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTONS_CLASS) ) {
            // A synthesized core/buttons wrapper is required for validity, but it
            // did not exist in the source. Flatten it so the next real box is the
            // one that participates in the parent's formatting context.
            $neutral = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTONS_CLASS;
            $inner = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS;
            $parts[] = ':where(.' . $neutral . '){display:contents!important}'
                // When the inner save wrapper still generates a box (positioned
                // controls), that box owns shrink-to-fit. `:not()` keeps these
                // declarations off a neutralized inner wrapper so they cannot
                // silently drop.
                . ':where(.' . $neutral . ')>.wp-block-button:not(.' . $inner . '){width:fit-content}'
                . ':where(.' . $neutral . ')>.wp-block-button:not(.' . $inner . ')>.wp-block-button__link{display:block;word-break:normal}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS) ) {
            // core/button always saves a .wp-block-button box around the control.
            // Flatten it when it is extra relative to the source, and transfer
            // shrink-to-fit plus inline participation onto the link — the box
            // that actually becomes the flex/grid/inline item.
            $neutralButton = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS;
            $parts[] = ':where(.' . $neutralButton . '){display:contents!important}'
                . ':where(.' . $neutralButton . ')>.wp-block-button__link{display:inline;width:fit-content;word-break:normal}';
        }
        if ( str_contains($serializedBlocks, self::EMPTY_FLEX_ITEM_CLASS) ) {
            $parts[] = ':where(.' . self::EMPTY_FLEX_ITEM_CLASS . '){flex:0 0 0!important;width:0!important;min-width:0!important;margin-left:0!important;margin-right:0!important}';
        }
        if ( str_contains($serializedBlocks, self::EMPTY_FLEX_ITEM_COLUMN_CLASS) ) {
            // A column-direction flex container's main axis is height, so
            // the compatibility box zeroes the vertical properties instead
            // of the row-axis rule's width/horizontal-margin set above.
            $parts[] = ':where(.' . self::EMPTY_FLEX_ITEM_COLUMN_CLASS . '){flex:0 0 0!important;height:0!important;min-height:0!important;margin-top:0!important;margin-bottom:0!important}';
        }
        if ( str_contains($serializedBlocks, HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS) ) {
            // An empty painted layer has no portable interaction contract. It
            // must not cover native controls after its source runtime is absent.
            $parts[] = ':where(.' . HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS . '){pointer-events:none!important}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_FLOW_CLASS) ) {
            // Core flow spacing is not part of a source grid or flex contract.
            // This precedes author CSS so source child margins remain authoritative.
            $parts[] = ':root :where(.wp-block-group.' . self::CSS_OWNED_FLOW_CLASS . ')>*{margin-block-start:0;margin-block-end:0}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_GRID_CLASS) ) {
            // Core flow margins are not part of a source grid contract; the
            // carried grid geometry (gap) owns the spacing between items. Native
            // headings retain their source browser-default margins unless the
            // author stylesheet overrides them.
            $parts[] = ':root :where(.' . self::CSS_OWNED_GRID_CLASS . ')>:where(:not(h1,h2,h3,h4,h5,h6)){margin-block-start:0;margin-block-end:0}'
                // A grid item's automatic minimum size defaults to its content's
                // min-content size, not zero (CSS Grid section 6.6). A source
                // track that collapses to an unauthored, implicit single column
                // at a narrow viewport (e.g. a Tailwind `grid` base with a
                // `sm:`/`md:` column count) relies on the *item* being safely
                // shrinkable so that implicit `auto` track can size to the
                // container instead of the item's intrinsic content. The source
                // usually gets that for free from an `overflow` other than
                // `visible` somewhere in the item's own subtree (CSS Sizing
                // section 4.1's "automatic minimum size" carve-out), commonly on
                // a wrapper around a large or aspect-ratio'd
                // image. Coalescing that wrapper into its parent block (an
                // accepted, already-diagnosed topology change — see
                // WrapperCoalescer) can carry the wrapper's visual properties
                // without carrying this load-bearing sizing side effect, and the
                // grid item is then free to force its track — and so itself —
                // wider than the container. A zero-specificity `min-width:0`
                // restores the same safe default the source had, without a
                // viewport check: the item still sizes to its full authored track
                // whenever that track has room, and only stops forcing the track
                // wider than the container when it does not.
                . "\n" . ':root :where(.' . self::CSS_OWNED_GRID_CLASS . ')>*{min-width:0}';
        }
        if ( str_contains($serializedBlocks, '<!-- wp:code') ) {
            // Core makes the inner code element a full-width break-spaces block.
            // Source pre/code is inline and inherits the preformatted whitespace
            // contract; restore that shape while authored code rules remain free
            // to choose typography.
            $parts[] = ':root :where(.wp-block-code)>code{display:inline;overflow-wrap:normal;text-align:inherit;white-space:inherit;direction:inherit}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::CSS_OWNED_INLINE_FLOW_CLASS) ) {
            // Block delimiters may acquire whitespace when Gutenberg saves the
            // post. Flex owns the source's atomic inline flow without counting
            // those text nodes as width; later responsive display rules still win.
            $parts[] = ':where(.' . SourceBlockAttributeProjector::CSS_OWNED_INLINE_FLOW_CLASS . '){display:flex;flex-wrap:wrap;align-items:baseline;gap:0}'
                . "\n" . ':where(.' . SourceBlockAttributeProjector::CSS_OWNED_INLINE_FLOW_CLASS . ')>*{flex:none}';
        }
        if ( str_contains($serializedBlocks, SourceBlockAttributeProjector::CSS_OWNED_LAYOUT_ITEM_CLASS) ) {
            // A semantic Group used as a direct grid/flex item contains native
            // paragraph blocks. Neutralize only those generated inner defaults.
            $parts[] = ':root :where(.wp-block-group.' . SourceBlockAttributeProjector::CSS_OWNED_LAYOUT_ITEM_CLASS . ')>*{margin-block-start:0;margin-block-end:0}';
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            $parts[] = '.wp-block-navigation.blocks-engine-list-navigation{align-items:normal}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item__content{display:inline}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__container{display:flex;flex-direction:inherit;align-items:inherit;flex-wrap:wrap;list-style:none}';
        }

        return $parts;
    }

    /**
     * Structural repairs to Gutenberg's own generated markup — table-column
     * flex geometry, and inheriting text colour across a content-wrapping
     * link core cannot see — tagged {@see CascadeLayer::GENERATED_MARKUP_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function generatedMarkupRepairCss(string $serializedBlocks): array
    {
        $parts = array();
        if ( str_contains($serializedBlocks, self::LAYOUT_TABLE_COLUMNS_CLASS) ) {
            $parts[] = ':root .wp-block-columns.' . self::LAYOUT_TABLE_COLUMNS_CLASS . '{display:flex;flex-wrap:nowrap;gap:0}'
                . "\n" . ':root .wp-block-columns.' . self::LAYOUT_TABLE_COLUMNS_CLASS . '>.wp-block-column{box-sizing:border-box;min-width:0}';
        }
        if ( str_contains($serializedBlocks, self::PROPAGATED_LINK_COLOR_CARRIER_CLASS) ) {
            // The source painted this text; the anchor around it only exists
            // because a content-wrapping link was pushed into the block. It
            // follows the author cascade so a later authored rule can still
            // repaint the link deliberately.
            $parts[] = ':root :where(.' . self::PROPAGATED_LINK_COLOR_CARRIER_CLASS . ')>a{color:inherit}';
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::GENERATED_MARKUP_REPAIR, $css),
            $parts
        );
    }

    /**
     * Repairs to core/social-links' own rendering, independent of anything
     * captured from the source — tagged {@see CascadeLayer::BLOCK_RENDER_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function socialLinkCss(string $serializedBlocks): array
    {
        $parts = array();
        if ( str_contains($serializedBlocks, 'wp:social-link') ) {
            // core/social-links paints its own icon for every service. The source
            // cluster painted icon-font glyphs through pseudo-elements on the very
            // items core now owns, so both icons would render on each link.
            $parts[] = ':root .wp-block-social-links .wp-social-link::before,'
                . ':root .wp-block-social-links .wp-social-link::after,'
                . ':root .wp-block-social-links .wp-social-link>a::before,'
                . ':root .wp-block-social-links .wp-social-link>a::after{content:none}';
            // The source cluster was an inline box, so its container's text
            // alignment placed it. core's list is a full-width flex row, which
            // packs the items at the start instead. An inline flex row resolves
            // through that same alignment, for centered and start-aligned
            // containers alike, while an explicit justification still wins.
            $parts[] = ':root ul.wp-block-social-links:not([class*="is-content-justification-"]){display:inline-flex}';
            $parts[] = ':root ul.wp-block-social-links.is-content-justification-left{justify-content:flex-start}'
                . ':root ul.wp-block-social-links.is-content-justification-center{justify-content:center}'
                . ':root ul.wp-block-social-links.is-content-justification-right{justify-content:flex-end}'
                . ':root ul.wp-block-social-links.is-content-justification-space-between{justify-content:space-between}';
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::BLOCK_RENDER_REPAIR, $css),
            $parts
        );
    }

    /**
     * Repairs for how a promoted list-navigation's host renders across its
     * responsive/dialog/sidebar/brand-carrier variants — tagged
     * {@see CascadeLayer::LIST_NAVIGATION_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function listNavigationHostRepairCss(string $serializedBlocks, string $mobileOverlayBackground): array
    {
        $parts = array();
        if ( ! str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            return array();
        }
        // Keep only source-responsive navigation hosts visible. Ordinary
        // link rows retain authored mobile display rules without core's
        // overlay control replacing them.
        if ( str_contains($serializedBlocks, 'blocks-engine-native-responsive-navigation') ) {
            $parts[] = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}';
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-sidebar-navigation-carrier') ) {
            // Core's mobile overlay is active at this breakpoint. The source
            // rail counterpart is intentionally collapsed there, so release
            // only the generated carrier and let native navigation own it.
            $parts[] = '@media(max-width:600px){nav.wp-block-group.blocks-engine-sidebar-navigation-carrier{position:relative!important;inset:auto!important;width:auto!important;height:auto!important;min-height:0!important;z-index:auto!important}nav.wp-block-group.blocks-engine-sidebar-navigation-carrier>.wp-block-navigation{width:100%!important;height:auto!important;min-height:48px!important}nav.wp-block-group.blocks-engine-sidebar-navigation-carrier>.wp-block-navigation>.wp-block-navigation__responsive-container-open{display:flex!important;width:48px!important;height:48px!important;padding:12px!important;visibility:visible!important}}';
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-projected-dialog-navigation') ) {
            $fallbackTextColor = '';
            if ( '' === $mobileOverlayBackground ) {
                $mobileOverlayBackground = '#fff';
                $fallbackTextColor = 'color:#111!important;';
            }
            $projectedOpenMenu = '.wp-block-navigation.blocks-engine-projected-dialog-navigation .wp-block-navigation__responsive-container.is-menu-open';
            $parts[] = $projectedOpenMenu . '{background:' . $mobileOverlayBackground . '!important;' . $fallbackTextColor . 'position:fixed!important;inset:0!important;padding:clamp(4rem,12vh,7rem) clamp(1.5rem,6vw,4rem) 2rem!important;overflow-y:auto!important;z-index:99998!important}'
                . "\n" . $projectedOpenMenu . ' .wp-block-navigation__responsive-container-content{align-items:flex-start!important;justify-content:flex-start!important;gap:1rem!important;width:100%!important}'
                . "\n" . $projectedOpenMenu . ' .wp-block-navigation__container{align-items:flex-start!important;gap:.75rem!important;width:100%!important}'
                . "\n" . $projectedOpenMenu . ' .wp-block-navigation-item__content{' . $fallbackTextColor . 'font-size:clamp(1.125rem,4vw,1.5rem)!important;line-height:1.4!important;padding:.5rem 0!important}'
                . "\n" . $projectedOpenMenu . ' .wp-block-navigation__responsive-container-close{background:#fff!important;color:#111!important;position:fixed!important;top:1rem!important;right:1rem!important;padding:.75rem!important;z-index:1!important}'
                . "\n" . 'body.admin-bar ' . $projectedOpenMenu . '{top:var(--wp-admin--admin-bar--height,32px)!important}'
                . "\n" . 'body.admin-bar ' . $projectedOpenMenu . ' .wp-block-navigation__responsive-container-close{top:calc(1rem + var(--wp-admin--admin-bar--height,32px))!important}';
        }
        // Size a carried menu to its content when it sits inside a brand
        // carrier. The carrier renders <nav> and core/navigation renders
        // another <nav> inside it, so an authored `header nav` rule matches
        // both, and the block's auto flex-basis resolves to the whole
        // available width where the authored <ul> was content-sized. The
        // landmark's `justify-content:space-between` then has nothing left
        // to distribute and the brand is squeezed until it wraps: measured
        // on silver-summit at 1366px, brand 181x44 and menu 308 at x=962
        // became 155x82 and menu 1005 at x=265. `max-width:100%` keeps the
        // block shrinkable, so a narrow viewport still hands over to core's
        // responsive overlay rather than overflowing the page.
        // core's navigation renderer repeats the block's class list on the
        // inner container, so a single authored box is painted twice: once
        // on the <nav> and again on its <ul>. Measured on busybearscleaning
        // at 1440px, an authored 19.3517px padding produced a 105.78px menu
        // against the source's 68.09px. The source declared that box on one
        // element, so the repeated container copy is neutralized and the
        // authored geometry keeps its single application.
        // An authored selector can outrank any generated one, so the reset
        // is declared important. The <nav> keeps the authored class list and
        // therefore still paints the source box exactly once, whether the
        // source declared it on the menu element or on its list.
        $parts[] = 'nav.wp-block-group.blocks-engine-brand-navigation-carrier>.wp-block-navigation.blocks-engine-list-navigation{width:max-content;max-width:100%}';

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::LIST_NAVIGATION_REPAIR, $css),
            $parts
        );
    }

    /**
     * Overlay-background and submenu-overflow follow-ups for a promoted
     * list-navigation, emitted once the host repair and item-anchor rules
     * are known — tagged {@see CascadeLayer::LIST_NAVIGATION_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function listNavigationOverlayRepairCss(string $serializedBlocks, string $mobileOverlayBackground): array
    {
        $parts = array();
        if ( ! str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            return array();
        }
        if ( '' !== $mobileOverlayBackground ) {
            $parts[] = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__responsive-container.is-menu-open{background:' . $mobileOverlayBackground . '!important}';
        }
        if ( str_contains($serializedBlocks, 'wp:navigation-submenu') ) {
            // Source shell containers commonly clip their original, in-flow
            // menu. Core's generated desktop submenu extends outside that
            // box, so release only converted Group ancestors that contain it.
            // Zero specificity lets an authored !important overflow remain
            // authoritative, and leaves Core's mobile overlay untouched.
            $parts[] = ':where(.wp-block-group:has(.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-submenu)){overflow:visible!important}';
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::LIST_NAVIGATION_REPAIR, $css),
            $parts
        );
    }

    /**
     * A second, later wave of block-render repairs (inline-navigation
     * display, social-links logo-only styling, source social-item spacing)
     * — tagged {@see CascadeLayer::SECONDARY_BLOCK_RENDER_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function secondaryBlockRenderRepairCss(string $serializedBlocks): array
    {
        $parts = array();
        if ( str_contains($serializedBlocks, 'blocks-engine-inline-navigation') ) {
            $parts[] = '.wp-block-navigation.blocks-engine-native-responsive-navigation.blocks-engine-inline-navigation{display:inline-flex!important}';
        }
        if ( str_contains($serializedBlocks, 'wp:social-links') ) {
            $parts[] = '.wp-block-social-links.is-style-logos-only .wp-social-link{background-image:none;background-color:transparent}';
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-source-social-item-spacing') ) {
            $parts[] = '.wp-block-social-links.blocks-engine-source-social-item-spacing{gap:0}';
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::SECONDARY_BLOCK_RENDER_REPAIR, $css),
            $parts
        );
    }
}

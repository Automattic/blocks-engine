<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use DOMElement;

/**
 * Projects author navigation styling onto the emitted navigation blocks.
 *
 * Extracted from HtmlTransformer as a collaborator rather than a mixin: every
 * dependency it needs from the transformer is declared on
 * {@see NavigationStyleProjectionContext}, so this class has no $this access to
 * the transformer and can be exercised without constructing one.
 */
final class NavigationStyleProjector
{
    /** The editor-only host a shell block renders its inner blocks inside. */
    private const EDITOR_INNER_BLOCKS_CLASS = 'blocks-engine-layout-shell-editor-inner-blocks';

    public function __construct(
        private readonly NavigationStyleProjectionContext $context,
        private readonly StyleResolver $styleResolver
    ) {
    }
    /**
     * Colour repairs for a "direct" (not promoted to a list) navigation —
     * tagged {@see CascadeLayer::DIRECT_NAVIGATION_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function directNavigationSupportCss(string $serializedBlocks): array
    {
        if ( ! str_contains($serializedBlocks, 'blocks-engine-direct-navigation') ) {
            return array();
        }

        $rules = array();

        if ( preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*\/?-->/s', $serializedBlocks, $matches) ) {
            foreach ( $matches[1] as $json ) {
                $attrs = json_decode($json, true);
                if ( ! is_array($attrs) ) {
                    continue;
                }

                $color = trim((string) ($attrs['style']['color']['text'] ?? ''));
                if ( '' === $color ) {
                    continue;
                }
                $safeColor = (string) ($this->styleResolver->styleAttributeMapper()->map(array( 'color' => $color ))['style']['color']['text'] ?? '');
                if ( '' === $safeColor ) {
                    continue;
                }

                $expectedMarker = 'blocks-engine-direct-navigation-link-color-' . substr(hash('sha256', $safeColor), 0, 12);
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                if ( ! in_array($expectedMarker, $classes, true) ) {
                    continue;
                }

                $selector = '.wp-block-navigation.blocks-engine-direct-navigation '
                    . '.wp-block-navigation-item.' . $expectedMarker
                    . '>.wp-block-navigation-item__content';
                $rules[$selector] = $selector . '{color:' . $safeColor . '}';
            }
        }

        $css = implode("\n", array_values($rules));

        return '' === $css ? array() : array( new CascadeRule(CascadeLayer::DIRECT_NAVIGATION_REPAIR, $css) );
    }

    /**
     * Core adds an unlayered flex display to every navigation block. Retain an
     * authored class-only display state on the same host, including its media
     * or layer conditions, so a desktop navigation can remain hidden while its
     * separate mobile trigger is visible.
     *
     * Tagged {@see CascadeLayer::DIRECT_NAVIGATION_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function directNavigationDisplayRules(string $serializedBlocks): array
    {
        $classes = $this->directNavigationHostClasses($serializedBlocks);
        if ( array() === $classes ) {
            return array();
        }

        $sourceRules = array_merge($this->context->sourceStyles()->staticRules(), $this->context->sourceStyles()->conditionalRules());
        $requiresBridge = false;
        foreach ( $sourceRules as $rule ) {
            $selector = trim((string) ($rule['selector'] ?? ''));
            if ( 1 !== preg_match('/^\.((?:\\\\.|[A-Za-z0-9_-])+)$/', $selector, $match) ) {
                continue;
            }
            $class = preg_replace('/\\\\(.)/', '$1', $match[1]) ?? $match[1];
            $display = preg_replace('/\s*!important\s*$/i', '', trim((string) ($rule['declarations']['display'] ?? ''))) ?? '';
            if ( isset($classes[$class]) && 'none' === strtolower($display) ) {
                $requiresBridge = true;
                break;
            }
        }
        if ( ! $requiresBridge ) {
            return array();
        }

        $rules = array();
        foreach ( $sourceRules as $rule ) {
            $selector = trim((string) ($rule['selector'] ?? ''));
            if ( 1 !== preg_match('/^\.((?:\\\\.|[A-Za-z0-9_-])+)$/', $selector, $match) ) {
                continue;
            }
            $class = preg_replace('/\\\\(.)/', '$1', $match[1]) ?? $match[1];
            $display = trim((string) ($rule['declarations']['display'] ?? ''));
            if ( '' === $display || ! isset($classes[$class]) ) {
                continue;
            }

            $target = ':root .wp-block-navigation.' . $match[1];
            $css = $target . '{display:' . preg_replace('/\s*!important\s*$/i', '', $display) . '!important}';
            foreach ( array_reverse(is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array()) as $condition ) {
                $css = trim((string) $condition) . '{' . $css . '}';
            }
            $rules[] = $css;
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::DIRECT_NAVIGATION_REPAIR, $css),
            array_values(array_unique($rules))
        );
    }

    /** @return array<string, true> */
    private function directNavigationHostClasses(string $serializedBlocks): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*-->/s', $serializedBlocks, $matches) ) {
            return array();
        }

        $classes = array();
        foreach ( $matches[1] as $json ) {
            $attrs = json_decode($json, true);
            if ( ! is_array($attrs) || str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-list-navigation') ) {
                continue;
            }
            foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $classes[$class] = true;
                }
            }
        }

        return $classes;
    }


    public function materializeEditorStaticStateStylesheet(string $projectedAuthorCss = ''): void
    {
        $rules = array();
        $anchorProjectionCss = $this->editorAnchorProjectionCss($projectedAuthorCss);
        if ( '' !== $anchorProjectionCss ) {
            $rules[] = $anchorProjectionCss;
            // The anchor projection restates author rules on the deterministic
            // wrapper classes, animations included. Settle those copies too, or
            // the editor keeps the source's hidden start keyframe on exactly the
            // elements the projection exists to reach.
            foreach ( ( new RevealAnimationSettler() )->settleRules($anchorProjectionCss) as $settledRule ) {
                $rules[] = $settledRule;
            }
        }
        if ( preg_match('/(?:^|[;{])\s*(?:-webkit-)?animation(?:-[a-z-]+)?\s*:/i', $this->context->authorStyles()->combinedCss()) ) {
            // The editor shows a settled document, so every animation is wound
            // past its end. A paused play state and a scroll-driven timeline
            // both have to be overridden for that to hold: without them the
            // negative delay lands on an animation that never advances, and the
            // element stays on whatever keyframe its fill mode paints.
            $rules[] = ':root *,:root *::before,:root *::after{animation-delay:-999999s!important;animation-iteration-count:1!important;animation-fill-mode:both!important;animation-play-state:running!important;animation-timeline:auto!important;transition:none!important}';
        }
        if ( $this->context->runtimeBehavior()->emptyRuntimeTargetGenerated() ) {
            $selector = ':root .' . HtmlTransformer::EMPTY_RUNTIME_TARGET_CLASS . '.wp-block-group__placeholder';
            $rules[] = $selector . '{flex-basis:auto!important;width:auto!important;min-width:10ch!important;min-height:1.2em!important}'
                . $selector . '>*{display:none!important}'
                . $selector . '::before{content:"Dynamic content";display:block;opacity:.45;white-space:nowrap}';
        }
        if ( $this->context->runtimeBehavior()->emptyVisualGroupGenerated() ) {
            $selector = ':root .' . HtmlTransformer::EMPTY_VISUAL_GROUP_CLASS . '.wp-block-group__placeholder';
            // A painted source layer holds geometry, not authored children, so
            // core's empty-group variation picker stacks unrelated layout
            // controls at the top of the document. Withhold that picker, and
            // reserve no height for it: the source layer is painted out of
            // normal flow, so any reserved height displaces every block after
            // it and moves the composition down the canvas.
            $rules[] = $selector . '{min-height:0!important;overflow:hidden!important}'
                . $selector . '>*{display:none!important}'
                // Core's large empty-group placeholder has a more specific
                // display declaration than the generic child selector.
                . $selector . '>.components-placeholder.is-large{display:none!important}'
                // WordPress 7.1 inserts an anonymous transport element around
                // empty groups. It must not become the containing block for an
                // authored absolute painted layer.
                . ':root .editor-styles-wrapper .block-editor-block-list__block>div:not([class]):not([id]):not([style]):has(>.' . HtmlTransformer::EMPTY_VISUAL_GROUP_CLASS . '.wp-block-group__placeholder){display:contents}';
        }
        if ( preg_match('/\bbody\b[^{}]*\{[^}]*(?:overflow\s*:\s*(?:hidden|clip)|height\s*:\s*100(?:d|s|l)?vh)/is', $this->context->authorStyles()->combinedCss()) ) {
            $rules[] = ':root body{overflow:auto!important;height:auto!important;min-height:100%!important;width:auto!important}';
        }

        foreach ( $this->styleResolver->closedStateRepairCssRules() as $repairRule ) {
            $rules[] = $repairRule;
        }

        $this->context->materializeStylesheetAsset($rules, 'editor-static-state', 'after-author', 'editor-static-state', 'editor');
    }

    private function editorAnchorProjectionCss(string $projectedAuthorCss = ''): string
    {
        $ids = array_fill_keys(array_filter(
            $this->context->authorStyles()->sourceElementIds(),
            fn (string $id): bool => '' !== SourceDom::safeAnchor($id)
        ), true);
        if ( array() === $ids ) {
            return '';
        }

        // An authored rule reaches its target through two hooks: the ancestor it
        // is scoped by, and the element it addresses. Projection already rewrote
        // the ancestor — a source attribute the editor drops becomes a generated
        // class that survives — so reading the projected stylesheet keeps that
        // half intact while this pass restates the id half. Reading the source
        // stylesheet keeps the spellings projection leaves alone, and a rule
        // that lands in both is the same declaration twice.
        $stylesheets = array($this->context->authorStyles()->combinedCss());
        if ( '' !== trim($projectedAuthorCss) ) {
            $stylesheets[] = $projectedAuthorCss;
        }

        $projections = array();
        foreach ( $stylesheets as $stylesheet ) {
            $projection = $this->projectEditorAnchorStylesheet($stylesheet, $ids);
            if ( '' !== $projection ) {
                $projections[] = $projection;
            }
        }

        return trim(implode("\n", $projections));
    }

    /** @param array<string, bool> $ids */
    private function projectEditorAnchorStylesheet(string $stylesheet, array $ids): string
    {
        $stateMarkers = $this->context->selectorProjections()->attributeNegationMarkers();

        return trim(( new CssStylesheetTransformer() )->transform(
            $stylesheet,
            function (string $prelude, string $body) use ($ids, $stateMarkers): array {
                $projected = array();
                $transported = array();
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $marker = $stateMarkers[trim($selector)] ?? '';
                    if ( '' !== $marker ) {
                        $selector = preg_replace(
                            '/:not\(\s*\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+))?\s*\]\s*\)/i',
                            ':not(.' . $marker . ')',
                            $selector
                        ) ?? $selector;
                    }
                    $replacement = self::projectAnchorIds($selector, $ids);
                    if ( $replacement !== $selector ) {
                        $projected[] = $replacement;
                        $throughInnerBlocks = self::throughEditorInnerBlocks($replacement);
                        if ( null !== $throughInnerBlocks ) {
                            $projected[] = $throughInnerBlocks;
                        }
                        $transportSelector = $this->editorTemplatePartTransportSelector($selector, $ids);
                        if ( null !== $transportSelector ) {
                            $transported[] = $transportSelector;
                        }
                    }
                }

                if ( array() === $projected ) {
                    return array();
                }

                $rules = array(array('prelude' => implode(',', $projected), 'body' => $body));
                $transportBody = $this->templatePartGridItemDeclarations($body);
                if ( array() !== $transported && '' !== $transportBody ) {
                    $rules[] = array('prelude' => implode(',', array_unique($transported)), 'body' => $transportBody);
                }
                return $rules;
            }
        ));
    }

    /**
     * Restate a child combinator so it still reaches its target across the
     * editor's inner-blocks host.
     *
     * A shell block renders its inner blocks inside one extra element on the
     * canvas. That host is `display:contents`, so the child still participates
     * in its grandparent's layout and the authored declaration is the right one
     * to apply — but a child combinator is matched on the tree, not on the
     * layout, so the authored `parent > child` stopped matching. Offer the same
     * declaration through the host as an additional alternative.
     *
     * Only the hop into the rightmost compound is relaxed: that is the one that
     * places the element, and widening every combinator would let an unrelated
     * ancestor match.
     */
    private static function throughEditorInnerBlocks(string $selector): ?string
    {
        $position = strrpos($selector, '>');
        if ( false === $position ) {
            return null;
        }

        return substr($selector, 0, $position + 1)
            . ':where(.' . self::EDITOR_INNER_BLOCKS_CLASS . ')>'
            . substr($selector, $position + 1);
    }

    /**
     * Restate an authored id target on the deterministic anchor class.
     *
     * The editor replaces a block wrapper's id with its own client id, so a
     * rule that addresses a component by id matches nothing there. Mesh
     * builders write those rules with `[id="…"]` at least as often as with
     * `#…` — on a Wix export every child placement is the attribute spelling —
     * so projecting only the `#` form left the container a grid while its
     * children lost `grid-area` and stacked in source order.
     *
     * @param array<string, bool> $ids
     */
    private static function projectAnchorIds(string $fragment, array $ids, bool $keepSourceClass = false): string
    {
        $anchor = static function (string $id) use ($ids, $keepSourceClass): ?string {
            if ( ! isset($ids[$id]) ) {
                return null;
            }
            $projected = '.blocks-engine-editor-anchor-' . $id;
            return $keepSourceClass ? ':is(' . $projected . ',.' . $id . ')' : $projected;
        };

        $fragment = preg_replace_callback(
            '/(^|[\s>+~,(])#([A-Za-z][A-Za-z0-9_-]*)/',
            static function (array $match) use ($anchor): string {
                $projected = $anchor($match[2]);
                return null === $projected ? $match[0] : $match[1] . $projected;
            },
            $fragment
        ) ?? $fragment;

        return preg_replace_callback(
            '/\[\s*id\s*=\s*(?:"([A-Za-z][A-Za-z0-9_-]*)"|\'([A-Za-z][A-Za-z0-9_-]*)\'|([A-Za-z][A-Za-z0-9_-]*))\s*\]/i',
            static function (array $match) use ($anchor): string {
                $id = '' !== ($match[1] ?? '') ? $match[1] : ('' !== ($match[2] ?? '') ? $match[2] : ($match[3] ?? ''));
                $projected = '' === $id ? null : $anchor($id);
                return null === $projected ? $match[0] : $projected;
            },
            $fragment
        ) ?? $fragment;
    }

    /** @param array<string, bool> $ids */
    private function editorTemplatePartTransportSelector(string $selector, array $ids): ?string
    {
        $parsed = CssSelectorMatcher::parse($selector);
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        $compound = $parsed['compounds'][count($parsed['compounds']) - 1] ?? null;
        if ( ! ($parsed['supported'] ?? false) || ! is_array($rightmost) || ! is_array($compound) ) {
            return null;
        }
        if ( array() === array_intersect(array_keys($ids), $compound['ids'] ?? array()) ) {
            return null;
        }

        $projectIds = static fn (string $fragment): string => self::projectAnchorIds($fragment, $ids);
        $projectTargetIds = static fn (string $fragment): string => self::projectAnchorIds($fragment, $ids, true);

        $start = (int) $rightmost['start'];
        $end = (int) $rightmost['end'];
        $prefix = $projectIds(substr($selector, 0, $start));
        $target = $projectTargetIds(substr($selector, $start, $end - $start));
        $suffix = substr($selector, $end);
        return $prefix . ':where(.wp-block-template-part):has(> ' . $target . ')' . $suffix;
    }

    private function templatePartGridItemDeclarations(string $body): string
    {
        $declarations = $this->styleResolver->cssDeclarations($body);
        $placement = array_filter(
            $declarations,
            static fn (string $name): bool => 'order' === $name
                || 'grid-area' === $name
                || str_starts_with($name, 'grid-row')
                || str_starts_with($name, 'grid-column'),
            ARRAY_FILTER_USE_KEY
        );
        return $this->styleResolver->cssDeclarationString($placement);
    }

    /**
     * Re-assert an authored inline-axis `auto` margin on the navigation block
     * host, after author CSS.
     *
     * A menu authored as `.navlinks{margin:0 0 0 auto}` inside `nav{display:flex}`
     * sits at the far end of its landmark. The class survives onto the promoted
     * navigation, but core's own navigation stylesheet owns the inner list —
     * `.wp-block-navigation ul{margin-left:0}`, specificity 0,1,1 — and outranks
     * the authored 0,1,0 class, so the menu snaps back to the start of the
     * landmark and the authored end-alignment is lost.
     *
     * The block host is the flex item that actually moves, so the margin is
     * restated there. The selector is self-limiting: it matches only an element
     * that is both a promoted list navigation and carries the authored class.
     *
     * Only `auto` is carried. An authored length is left to the author rule,
     * which core does not contest on the host.
     *
     * Part of the list-navigation repair set — tagged {@see CascadeLayer::LIST_NAVIGATION_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function listNavigationInlineMarginRules(string $serializedBlocks): array
    {
        if ( ! str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            return array();
        }

        $navigationClasses = $this->listNavigationHostClasses($serializedBlocks);
        if ( array() === $navigationClasses ) {
            return array();
        }

        $rules = array();
        foreach ( array_merge($this->context->sourceStyles()->staticRules(), $this->context->sourceStyles()->conditionalRules()) as $rule ) {
            $selector = trim((string) ($rule['selector'] ?? ''));
            if ( 1 !== preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)$/', $selector, $match) ) {
                continue;
            }

            $class = $match[1];
            // The class has to sit on a promoted navigation host, not merely
            // appear somewhere in the document. A page wrapper's `.wrap{margin:0
            // auto}` is not a statement about a menu, and emitting a rule for it
            // would be dead CSS on every page that has one.
            if ( ! isset($navigationClasses[$class]) ) {
                continue;
            }

            $margins = $this->inlineAxisAutoMargins(is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array());
            if ( array() === $margins ) {
                continue;
            }

            $declarations = array();
            foreach ( $margins as $side => $value ) {
                $declarations[] = 'margin-' . $side . ':' . $value . '!important';
            }

            $selectorText = '.wp-block-navigation.blocks-engine-list-navigation.' . $class;
            $rules[$selectorText] = $selectorText . '{' . implode(';', $declarations) . '}';
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::LIST_NAVIGATION_REPAIR, $css),
            array_values($rules)
        );
    }

    /**
     * Part of the list-navigation repair set — tagged {@see CascadeLayer::LIST_NAVIGATION_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function listNavigationPaddingRules(string $serializedBlocks): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $serializedBlocks, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $paddingSets = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            if ( ! in_array('blocks-engine-list-navigation', $classes, true) ) {
                continue;
            }

            $padding = is_array($attrs['style']['spacing']['padding'] ?? null)
                ? $attrs['style']['spacing']['padding']
                : array();
            if ( array() === $padding ) {
                foreach ( $classes as $class ) {
                    $fallbackPadding = $this->context->generatedSupportStyles()->listNavigationPadding($class);
                    if ( array() !== $fallbackPadding ) {
                        $padding = $fallbackPadding;
                        break;
                    }
                }
            }
            $declarations = array();
            foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                $property = 'padding-' . $side;
                $value = trim((string) ($padding[$side] ?? ''));
                $safe = $this->styleResolver->safeVisualDeclarations($this->styleResolver->cssDeclarations($property . ':' . $value));
                if ( '' !== $value
                    && ($safe[$property] ?? null) === $value
                    && ! $this->navigationDeclarationIsImportant($value)
                ) {
                    $declarations[] = $property . ':' . $value;
                }
            }
            if ( array() === $declarations ) {
                $paddingSets['__no_list_navigation_padding__'] = true;
                continue;
            }
            $paddingSets[implode(';', $declarations)] = true;
        }

        // One transform can contain multiple promoted menus. A shared selector
        // is exact only when their source-list padding agrees; otherwise fail
        // closed instead of letting source order assign one menu's box to all.
        if ( 1 !== count($paddingSets) || isset($paddingSets['__no_list_navigation_padding__']) ) {
            return array();
        }

        $selector = 'nav.wp-block-group>.wp-block-navigation.blocks-engine-list-navigation';

        return array( new CascadeRule(CascadeLayer::LIST_NAVIGATION_REPAIR, $selector . '{' . array_key_first($paddingSets) . '}') );
    }

    /**
     * Re-point an authored ANCHOR-scoped menu-item rule at the element core
     * actually renders.
     *
     * A design styles a menu CTA through its anchor — sunny-ember writes
     * `.navlinks a.nav-cta{background;color;padding}`. core/navigation-link puts
     * the authored class on the `<li>` and hard-codes the anchor's own class in
     * `render_block_core_navigation_link()`, so the source anchor class lands on
     * the navigation item rather than the rendered anchor. The authored selector
     * therefore matches nothing and the pill renders as plain text.
     *
     * The rule is rewritten onto `.wp-block-navigation-item.<class> >
     * .wp-block-navigation-item__content`, which is the anchor the class-bearing
     * item owns. Emitted after the author stylesheet and carrying five class
     * tokens, so it outranks both core's item styles and the authored rule it
     * stands in for.
     *
     * Source ownership, rather than selector spelling, triggers the mapping. A
     * bare `.nav-cta` is mapped when that class sat on the authored anchor just
     * like `.navlinks a.nav-cta`; a class authored on the source `<li>` remains
     * item-owned. Scope stays narrow: the class must ride a real navigation-link
     * in this document, and any ancestor part of the authored selector must name
     * a promoted navigation host — otherwise `.footer a.nav-cta` would be hoisted
     * into a menu it was never about.
     *
     * A mapped declaration is emitted only when its source rule actually wins
     * that exact property on every source anchor the mapped selector will reach.
     * This prevents the stronger compatibility selector from promoting a losing
     * authored declaration over the rule that beat it in the design.
     *
     * Part of the list-navigation repair set — tagged {@see CascadeLayer::LIST_NAVIGATION_REPAIR}.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return list<CascadeRule>
     */
    public function listNavigationItemAnchorRules(string $serializedBlocks, array $sourceProvenance): array
    {
        if ( ! str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            return array();
        }

        $itemClasses = $this->listNavigationItemClasses($serializedBlocks);
        if ( array() === $itemClasses ) {
            return array();
        }

        $anchorClasses = $this->listNavigationAnchorClasses($sourceProvenance);
        if ( array() === $anchorClasses ) {
            return array();
        }

        $hostClasses = $this->listNavigationHostClasses($serializedBlocks);
        $authoredRules = $this->navigationAuthorStyleRules();
        if ( array() === $authoredRules ) {
            return array();
        }

        $rules = array();
        $emitted = array();
        foreach ( $authoredRules as $rule ) {
            // Existing navigation compatibility CSS covers resting paint only;
            // pseudo-state mapping remains a deliberate, tested omission. Keep
            // pseudo context in the collector so it cannot compete with base.
            if ( '' !== ($rule['pseudo'] ?? '') ) {
                continue;
            }
            $selector = trim((string) ($rule['selector'] ?? ''));
            $ancestor = '';
            $class = '';
            $pseudo = '';
            $bareAnchorClassRule = false;
            $scopedAnchorClassRule = false;
            if ( 1 === preg_match('/^(.*?)(?:^|\s)a\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $match) ) {
                $ancestor = trim($match[1]);
                $class = $match[2];
                $pseudo = $match[3];
            } elseif ( 1 === preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $match) ) {
                $class = $match[1];
                $pseudo = $match[2];
                $bareAnchorClassRule = true;
            } elseif ( 1 === preg_match('/^(.*?)\s+\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $match) ) {
                $ancestor = trim($match[1]);
                $class = $match[2];
                $pseudo = $match[3];
                $bareAnchorClassRule = true;
                $scopedAnchorClassRule = true;
            } else {
                continue;
            }

            if ( ! isset($itemClasses[$class], $anchorClasses[$class]) ) {
                continue;
            }

            // `li.item .link`: the rule reaches the anchor through its own
            // source list item. Core renders that item as the navigation-link
            // `<li>` carrying both the item's and the anchor's classes, so the
            // item compound folds into the item selector instead of staying an
            // ancestor that no longer exists above it.
            $itemCompound = '';
            if ( $scopedAnchorClassRule
                && 1 === preg_match('/^(?:(.*?)\s+)?(?:li)?((?:\.[A-Za-z_][A-Za-z0-9_-]*)+)$/i', $ancestor, $itemMatch)
                && ! $this->namesNavigationHost($itemMatch[2], $hostClasses)
                && $this->namesOnlyNavigationItemClasses($itemMatch[2], $itemClasses)
            ) {
                $ancestor = trim($itemMatch[1]);
                $itemCompound = $itemMatch[2];
                $scopedAnchorClassRule = '' !== $ancestor;
            }

            if ( '' !== $ancestor && ! $this->namesNavigationHost($ancestor, $hostClasses) ) {
                continue;
            }
            if ( $scopedAnchorClassRule && (
                1 !== preg_match('/(?:^|[\s>+~])([^\s>+~]+)$/', $ancestor, $ancestorMatch)
                || ! $this->namesNavigationHost($ancestorMatch[1], $hostClasses)
            ) ) {
                // Core replaces the list structure below the promoted host.
                // Do not retain an intermediary source li/ul as an ancestor.
                continue;
            }

            $sourceAnchors = $this->navigationSourceAnchorsForClass($class, $sourceProvenance);
            if ( $scopedAnchorClassRule || '' !== $itemCompound ) {
                // The projected selector retains this ancestor context, so
                // same-class links in another menu cannot affect its winners.
                $sourceAnchors = array_values(array_filter(
                    $sourceAnchors,
                    fn (DOMElement $anchor): bool => $this->styleResolver->matchesCssSelector($anchor, $selector)
                ));
            }
            if ( array() === $sourceAnchors ) {
                continue;
            }
            if ( '' !== $itemCompound && ! $this->eachAnchorItemMatches($sourceAnchors, 'li' . $itemCompound) ) {
                // The source rule reached some anchor through an outer list
                // item; core's item-own selector cannot express that reach.
                continue;
            }

            $declarations = array();
            $itemNeutralizers = array();
            foreach ( is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array() as $property => $value ) {
                $property = trim((string) $property);
                $value = trim((string) $value);
                if ( '' === $property || '' === $value ) {
                    continue;
                }

                if ( 'border' === $property ) {
                    foreach ( $this->navigationBorderWinnerDeclarations($rule, $value, $authoredRules, $sourceAnchors) as $borderDeclaration ) {
                        $declarations[] = $borderDeclaration;
                    }
                } elseif ( $this->navigationRuleWinsPropertyOnAnchors($rule, $property, $authoredRules, $sourceAnchors) ) {
                    $declarations[] = $property . ':' . $value;
                }

                if ( $bareAnchorClassRule ) {
                    // core/navigation-link moves the authored anchor class onto
                    // its li. The bare rule then paints a second box that did not
                    // exist in the source, even for declarations also projected
                    // onto the rendered anchor. Restore the source li's exact
                    // winner, or its lower-origin value when no author rule owned
                    // that property. Ambiguous shorthand/longhand overlap fails
                    // closed instead of inventing a reset.
                    $resetValue = $this->navigationSourceListItemResetValue($class, $property, $sourceAnchors);
                    if ( null !== $resetValue ) {
                        $itemNeutralizers[] = $property . ':' . $resetValue;
                    }
                }
            }
            if ( array() === $declarations && array() === $itemNeutralizers ) {
                continue;
            }

            $emissionKey = implode("\0", array(
                (string) ($rule['id'] ?? ''),
                $class,
                (string) ($rule['pseudo'] ?? ''),
                (string) json_encode($rule['conditions'] ?? array()),
            ));
            if ( isset($emitted[$emissionKey]) ) {
                continue;
            }
            $emitted[$emissionKey] = true;

            $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array();
            // Keep a descendant class rule's authored context in both targets.
            // Its class moves from the source anchor onto core's item wrapper,
            // so both the anchor projection and item reset need that context.
            $itemSelector = $scopedAnchorClassRule
                ? $ancestor . ' .wp-block-navigation-item' . $itemCompound . '.' . $class
                : '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item' . $itemCompound . '.' . $class;
            if ( array() !== $declarations ) {
                $selectorText = $itemSelector . '>.wp-block-navigation-item__content' . $pseudo;
                $mappedRule = $selectorText . '{' . implode(';', $declarations) . '}';
                foreach ( array_reverse($conditions) as $condition ) {
                    $mappedRule = $condition . '{' . $mappedRule . '}';
                }
                $rules[] = $mappedRule;
            }

            if ( array() !== $itemNeutralizers ) {
                $itemRule = $itemSelector . '{' . implode(';', array_values(array_unique($itemNeutralizers))) . '}';
                foreach ( array_reverse($conditions) as $condition ) {
                    $itemRule = $condition . '{' . $itemRule . '}';
                }
                $rules[] = $itemRule;
            }
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::LIST_NAVIGATION_REPAIR, $css),
            $rules
        );
    }

    /**
     * Re-point authored interaction colours at rendered navigation anchors.
     *
     * Resting declarations are handled by listNavigationItemAnchorRules(),
     * which proves each source-cascade winner before increasing specificity.
     * State rules apply the same winner proof before mapping design-time current
     * classes onto WordPress runtime current state, including direct-anchor
     * navigation. Compatibility output stays colour-only. Conditional state
     * rules fail closed: their active cascade also includes unconditional rules,
     * so comparing an isolated condition stack cannot prove a global winner.
     *
     * Tagged {@see CascadeLayer::NAVIGATION_STATE_REPAIR}.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return list<CascadeRule>
     */
    public function navigationItemStateAnchorRules(string $serializedBlocks, array $sourceProvenance): array
    {
        $hasListNavigation = str_contains($serializedBlocks, 'blocks-engine-list-navigation');
        if ( ! str_contains($serializedBlocks, '<!-- wp:navigation ') ) {
            return array();
        }

        $itemClasses = $this->listNavigationItemClasses($serializedBlocks);
        $listHostClasses = $this->listNavigationHostClasses($serializedBlocks);
        $allHostClasses = $this->listNavigationHostClasses($serializedBlocks, false);
        $authoredRules = $this->navigationAuthorStyleRules();
        $rules = array();
        foreach ( $authoredRules as $rule ) {
            if ( array() !== ($rule['conditions'] ?? array()) ) {
                continue;
            }
            $selector = trim((string) ($rule['selector'] ?? ''));
            $match = array();
            if ( 1 === preg_match('/^(.*?)(?:^|\s)a\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $anchorMatch) ) {
                $match = array( $anchorMatch[1], $anchorMatch[2], $anchorMatch[3], 'anchor' );
            } elseif ( 1 === preg_match('/^(.*?)(?:^|\s)\.([A-Za-z_][A-Za-z0-9_-]*)\s*>\s*a((?::[a-z-]+)*)$/', $selector, $itemMatch) ) {
                $match = array( $itemMatch[1], $itemMatch[2], $itemMatch[3], 'item' );
            }
            if ( array() === $match ) {
                continue;
            }

            $ancestor = trim($match[0]);
            $class = $match[1];
            $pseudo = strtolower($match[2]);
            $classOwner = $match[3];
            if ( ! in_array($pseudo, array( ':hover', ':focus', ':focus-visible', ':active' ), true) ) {
                continue;
            }
            $isCurrentClass = $this->isAuthoredCurrentNavigationClass($class);
            if ( ! $isCurrentClass && (! $hasListNavigation || ! isset($itemClasses[$class])) ) {
                continue;
            }

            $hostClasses = $isCurrentClass ? $allHostClasses : $listHostClasses;
            if ( '' !== $ancestor && ! $this->namesNavigationHost($ancestor, $hostClasses) ) {
                continue;
            }

            $sourceAnchors = 'anchor' === $classOwner
                ? $this->navigationSourceAnchorsForClass($class, $sourceProvenance)
                : $this->navigationSourceAnchorsForItemClass($class, $sourceProvenance);
            if ( array() === $sourceAnchors
                || ! $this->navigationRuleWinsPropertyOnAnchors($rule, 'color', $authoredRules, $sourceAnchors)
                || $this->navigationRuleHasConditionalPropertyCompetitorOnAnchors($rule, 'color', $authoredRules, $sourceAnchors)
            ) {
                continue;
            }

            $source = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array();
            $source = isset($source['color']) ? array( 'color' => $source['color'] ) : array();
            if ( array() === $source ) {
                continue;
            }

            $declarations = array();
            foreach ( $source as $property => $value ) {
                $property = trim((string) $property);
                $value = trim((string) $value);
                if ( '' === $property || '' === $value ) {
                    continue;
                }
                $declarations[] = $property . ':' . $value;
            }
            if ( array() === $declarations ) {
                continue;
            }

            if ( $isCurrentClass ) {
                $hostSelector = '.wp-block-navigation';
                if ( preg_match_all('/\.([A-Za-z_][A-Za-z0-9_-]*)/', $ancestor, $hostMatches) ) {
                    foreach ( $hostMatches[1] as $hostClass ) {
                        if ( isset($hostClasses[$hostClass]) ) {
                            $hostSelector .= '.' . $hostClass;
                        }
                    }
                }
                $selectorText = $hostSelector
                    . ' .wp-block-navigation-item.current-menu-item>.wp-block-navigation-item__content' . $pseudo
                    . ',' . $hostSelector
                    . ' .wp-block-navigation-item__content[aria-current]' . $pseudo;
            } else {
                $selectorText = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.'
                    . $class . '>.wp-block-navigation-item__content' . $pseudo;
            }
            $rules[$selectorText] = $selectorText . '{' . implode(';', $declarations) . '}';
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::NAVIGATION_STATE_REPAIR, $css),
            array_values($rules)
        );
    }

    /**
     * Ordered authored rules used only by navigation anchor compatibility CSS.
     *
     * The canonical author analysis retains rule identity, condition stack,
     * pseudo suffix, and source order. Navigation adds only its compatibility
     * declaration filter and specificity projection.
     *
     * @return array<int, array<string, mixed>>
     */
    private function navigationAuthorStyleRules(): array
    {
        $rules = array();
        $order = 0;
        foreach ( $this->context->authorStyles()->styleRules() as $authorRule ) {
            $declarations = $this->styleResolver->safeVisualDeclarations(
                is_array($authorRule['declarations'] ?? null) ? $authorRule['declarations'] : array()
            );
            if ( array() === $declarations ) {
                continue;
            }
            $ruleId = $order++;
            foreach ( is_array($authorRule['selectors'] ?? null) ? $authorRule['selectors'] : array() as $authorSelector ) {
                $selector = trim((string) ($authorSelector['selector'] ?? ''));
                $parsed = is_array($authorSelector['parsed'] ?? null) ? $authorSelector['parsed'] : array();
                if ( '' === $selector || ! ($parsed['supported'] ?? false) ) {
                    continue;
                }
                $pseudo = '';
                $pseudoSpan = $parsed['pseudo_state_suffix_span'] ?? null;
                if ( is_array($pseudoSpan) ) {
                    $pseudo = strtolower(substr($selector, $pseudoSpan['start'], $pseudoSpan['end'] - $pseudoSpan['start']));
                }
                $rules[] = array(
                    'id' => $ruleId,
                    'selector' => $selector,
                    'parsed' => $parsed,
                    'declarations' => $declarations,
                    'conditions' => is_array($authorRule['conditions'] ?? null) ? $authorRule['conditions'] : array(),
                    'pseudo' => $pseudo,
                    'specificity' => $this->navigationSelectorSpecificity($parsed, $pseudo),
                    'order' => $ruleId,
                );
            }
        }
        return $rules;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array{int, int, int}
     */
    private function navigationSelectorSpecificity(array $parsed, string $pseudo): array
    {
        $specificity = array( 0, 0, 0 );
        $addCompound = function (array $compound) use (&$addCompound, &$specificity): void {
            $specificity[0] += count($compound['ids'] ?? array());
            $specificity[1] += count($compound['classes'] ?? array()) + count($compound['attributes'] ?? array());
            if ( null !== ($compound['nth_child'] ?? null) || ($compound['first_child'] ?? false) || ($compound['last_child'] ?? false) ) {
                ++$specificity[1];
            }
            if ( null !== ($compound['type'] ?? null) ) {
                ++$specificity[2];
            }
            foreach ( $compound['not'] ?? array() as $negated ) {
                $addCompound($negated);
            }
        };
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            $addCompound($compound);
        }
        $specificity[1] += preg_match_all('/:[a-z-]+/i', $pseudo);
        return $specificity;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return list<DOMElement>
     */
    private function navigationSourceAnchorsForClass(string $class, array $sourceProvenance): array
    {
        $selectors = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( ! in_array($entry['block_name'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
                continue;
            }
            if ( in_array($class, $this->navigationSourceOwnershipClasses($entry, 'anchor'), true) ) {
                $selector = (string) ($entry['navigation_source_ownership']['anchor']['selector'] ?? $entry['selector'] ?? '');
                if ( '' !== $selector ) {
                    $selectors[$selector] = true;
                }
            }
        }
        if ( array() === $selectors ) {
            return array();
        }

        $anchors = array();
        foreach ( $this->context->authorStyles()->sourceElementsByClass($class) as $element ) {
            if ( $element instanceof DOMElement
                && 'a' === strtolower($element->tagName)
                && isset($selectors[SourceDom::elementSelector($element)])
            ) {
                $anchors[] = $element;
            }
        }
        return $anchors;
    }

    /**
     * Source navigation anchors directly owned by a class-bearing source item.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return list<DOMElement>
     */
    private function navigationSourceAnchorsForItemClass(string $class, array $sourceProvenance): array
    {
        $selectors = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( 'core/navigation-link' === ($entry['block_name'] ?? '') && 'a' === ($entry['tag'] ?? '') ) {
                $selectors[(string) ($entry['selector'] ?? '')] = true;
            }
        }
        if ( array() === $selectors ) {
            return array();
        }

        $anchors = array();
        foreach ( $this->context->authorStyles()->sourceElementsByClass($class) as $item ) {
            if ( ! $item instanceof DOMElement ) {
                continue;
            }
            foreach ( $item->childNodes as $child ) {
                if ( $child instanceof DOMElement
                    && 'a' === strtolower($child->tagName)
                    && isset($selectors[SourceDom::elementSelector($child)])
                ) {
                    $anchors[] = $child;
                }
            }
        }
        return $anchors;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     */
    private function navigationRuleWinsPropertyOnAnchors(array $candidate, string $property, array $authoredRules, array $anchors): bool
    {
        foreach ( $anchors as $anchor ) {
            $winner = null;
            foreach ( $authoredRules as $rule ) {
                if ( ($candidate['conditions'] ?? array()) !== ($rule['conditions'] ?? array())
                    || ($candidate['pseudo'] ?? '') !== ($rule['pseudo'] ?? '')
                    || ! array_key_exists($property, is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array())
                ) {
                    continue;
                }
                $match = CssSelectorMatcher::matches($anchor, $rule['parsed'], true);
                if ( ! $match['supported'] || ! $match['matches'] ) {
                    continue;
                }
                $entry = array(
                    'id' => $rule['id'],
                    'important' => $this->navigationDeclarationIsImportant((string) $rule['declarations'][$property]),
                    'specificity' => $rule['specificity'],
                    'order' => $rule['order'],
                );
                if ( null === $winner || $this->navigationCascadeEntryWins($entry, $winner) ) {
                    $winner = $entry;
                }
            }

            if ( array() === ($candidate['conditions'] ?? array()) && '' === ($candidate['pseudo'] ?? '') ) {
                $inline = $this->styleResolver->safeVisualDeclarations($this->styleResolver->cssDeclarations(SourceDom::attr($anchor, 'style')));
                if ( array_key_exists($property, $inline) ) {
                    $entry = array(
                        'id' => -1,
                        'important' => $this->navigationDeclarationIsImportant((string) $inline[$property]),
                        'specificity' => array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ),
                        'order' => PHP_INT_MAX,
                    );
                    if ( null === $winner || $this->navigationCascadeEntryWins($entry, $winner) ) {
                        $winner = $entry;
                    }
                }
            }

            if ( ! is_array($winner) || ($candidate['id'] ?? null) !== $winner['id'] ) {
                return false;
            }
        }
        return array() !== $anchors;
    }

    /**
     * Fail closed when a conditioned rule can join the same source cascade.
     *
     * Condition stacks include layers and scopes whose ordering cannot be
     * proven by the selector-only comparison above. Restrict the abstention to
     * rules that set the same property in the same state on a mapped anchor.
     *
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     */
    private function navigationRuleHasConditionalPropertyCompetitorOnAnchors(array $candidate, string $property, array $authoredRules, array $anchors): bool
    {
        foreach ( $authoredRules as $rule ) {
            if ( array() === ($rule['conditions'] ?? array())
                || ($candidate['pseudo'] ?? '') !== ($rule['pseudo'] ?? '')
                || ! array_key_exists($property, is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array())
            ) {
                continue;
            }
            foreach ( $anchors as $anchor ) {
                $match = CssSelectorMatcher::matches($anchor, $rule['parsed'], true);
                if ( $match['supported'] && $match['matches'] ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Expand a border shorthand before projection so stronger authored side
     * rules keep winning the same longhands they won in the source cascade.
     *
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     * @return list<string>
     */
    private function navigationBorderWinnerDeclarations(array $candidate, string $value, array $authoredRules, array $anchors): array
    {
        $mapped = ( new StyleAttributeMapper() )->map(array( 'border' => $value ));
        $border = is_array($mapped['style']['border'] ?? null) ? $mapped['style']['border'] : array();
        $components = array_filter(array(
            'width' => trim((string) ($border['width'] ?? '')),
            'style' => trim((string) ($border['style'] ?? '')),
            'color' => trim((string) ($border['color'] ?? '')),
        ), static fn (string $componentValue): bool => '' !== $componentValue);
        if ( 3 !== count($components) ) {
            return array();
        }

        $declarations = array();
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            foreach ( $components as $component => $componentValue ) {
                $virtualProperty = 'border-' . $side . '-' . $component;
                if ( $this->navigationRuleWinsBorderVirtualPropertyOnAnchors(
                    $candidate,
                    $virtualProperty,
                    $authoredRules,
                    $anchors
                ) ) {
                    $declarations[] = $virtualProperty . ':' . $componentValue;
                }
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     */
    private function navigationRuleWinsBorderVirtualPropertyOnAnchors(array $candidate, string $virtualProperty, array $authoredRules, array $anchors): bool
    {
        $virtualRules = array();
        foreach ( $authoredRules as $rule ) {
            $virtualValue = null;
            foreach ( is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array() as $property => $value ) {
                if ( $this->navigationBorderDeclarationAffectsVirtualProperty((string) $property, $virtualProperty) ) {
                    $virtualValue = (string) $value;
                }
            }
            if ( null === $virtualValue ) {
                continue;
            }
            $rule['declarations'] = array( $virtualProperty => $virtualValue );
            $virtualRules[] = $rule;
        }

        return $this->navigationRuleWinsPropertyOnAnchors($candidate, $virtualProperty, $virtualRules, $anchors);
    }

    private function navigationBorderDeclarationAffectsVirtualProperty(string $property, string $virtualProperty): bool
    {
        if ( 1 !== preg_match('/^border-(top|right|bottom|left)-(width|style|color)$/', $virtualProperty, $match) ) {
            return false;
        }

        return in_array($property, array(
            'border',
            'border-' . $match[1],
            'border-' . $match[2],
            $virtualProperty,
        ), true);
    }

    /**
     * @param list<DOMElement> $anchors
     */
    private function navigationSourceListItemResetValue(string $class, string $property, array $anchors): ?string
    {
        $values = array();
        foreach ( $anchors as $anchor ) {
            $item = $this->navigationSourceAnchorOwnListItem($anchor, $class, $property);
            if ( null === $item ) {
                return null;
            }

            $itemClasses = preg_split('/\s+/', trim(SourceDom::attr($item, 'class'))) ?: array();
            if ( in_array($class, $itemClasses, true) ) {
                // The class also belonged to the source item. Its item paint is
                // authored, not an artifact of core moving the anchor class.
                return null;
            }

            $itemDeclarations = $this->styleResolver->safeVisualDeclarations(
                $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($item))
            );
            foreach ( $itemDeclarations as $itemProperty => $_itemValue ) {
                if ( $itemProperty !== $property && $this->navigationPropertiesOverlap($property, $itemProperty) ) {
                    return null;
                }
            }

            $value = trim((string) ($itemDeclarations[$property] ?? 'revert'));
            if ( '' === $value || $this->navigationDeclarationIsImportant($value) ) {
                return null;
            }
            $values[$value] = true;
        }

        return 1 === count($values) ? (string) array_key_first($values) : null;
    }

    /**
     * The source `<li>` whose collapsed chain a navigation anchor's classes
     * land on.
     *
     * A mesh menu nests its anchor inside wrapper elements — Wix's stylable
     * menu renders `li > div > a` — and the collapse hoists every chain
     * element's classes onto the one rendered item. The anchor's own class is
     * among them, so the item reset is needed exactly as it is for a direct
     * `li > a`; requiring a direct parent left the second box in place for
     * every mesh menu.
     *
     * The reset stays sound only while no wrapper between the anchor and its
     * item painted the property itself: such a wrapper's box was real, its
     * class rules now reach the rendered item, and a reset would erase paint
     * the source actually had. A wrapper that carries the anchor class, or
     * whose resolved presentation paints an overlapping property, therefore
     * fails closed, as does an anchor that reaches the list root without
     * finding an item. The zero declarations a global reset stamps on every
     * element (`margin:0`, `border:0`, `background:0 0`) are not paint and do
     * not block the reset — refusing them would make the walk refuse every
     * document that ships a reset stylesheet, which is exactly the mesh
     * corpus this exists for.
     */
    private function navigationSourceAnchorOwnListItem(DOMElement $anchor, string $class, string $property): ?DOMElement
    {
        $node = $anchor->parentNode;
        while ( $node instanceof DOMElement && 'li' !== strtolower($node->tagName) ) {
            if ( in_array(strtolower($node->tagName), array( 'ul', 'ol', 'menu', 'nav' ), true) ) {
                return null;
            }

            $wrapperClasses = preg_split('/\s+/', trim(SourceDom::attr($node, 'class'))) ?: array();
            if ( in_array($class, $wrapperClasses, true) ) {
                return null;
            }

            $wrapperDeclarations = $this->styleResolver->safeVisualDeclarations(
                $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($node))
            );
            foreach ( $wrapperDeclarations as $wrapperProperty => $wrapperValue ) {
                if ( $this->navigationPropertiesOverlap($property, (string) $wrapperProperty)
                    && ! $this->navigationDeclarationIsPaintFree((string) $wrapperValue)
                ) {
                    return null;
                }
            }

            $node = $node->parentNode;
        }

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * Whether a resolved declaration cannot paint or reserve space: every
     * token is a zero length, `none`, or `transparent` — the values a global
     * reset assigns. `auto`, colours, and any nonzero length are paint.
     */
    private function navigationDeclarationIsPaintFree(string $value): bool
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: array();
        if ( array() === $tokens ) {
            return false;
        }
        foreach ( $tokens as $token ) {
            if ( 1 !== preg_match('/^(?:[+-]?0+(?:\.0+)?(?:[a-z]+|%)?|none|transparent)$/i', $token) ) {
                return false;
            }
        }

        return true;
    }

    private function navigationPropertiesOverlap(string $first, string $second): bool
    {
        if ( $first === $second ) {
            return true;
        }

        foreach ( array( 'background', 'border', 'font', 'margin', 'padding' ) as $family ) {
            $firstInFamily = $family === $first || str_starts_with($first, $family . '-');
            $secondInFamily = $family === $second || str_starts_with($second, $family . '-');
            if ( $firstInFamily && $secondInFamily && ($family === $first || $family === $second) ) {
                return true;
            }
        }

        return false;
    }

    private function navigationDeclarationIsImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!\s*important\s*$/i', $value);
    }

    /**
     * @param array{id: int, important: bool, specificity: array{int, int, int}, order: int} $candidate
     * @param array{id: int, important: bool, specificity: array{int, int, int}, order: int} $current
     */
    private function navigationCascadeEntryWins(array $candidate, array $current): bool
    {
        if ( $candidate['important'] !== $current['important'] ) {
            return $candidate['important'];
        }
        $specificity = $this->styleResolver->compareMediaTextSpecificity($candidate['specificity'], $current['specificity']);
        return 0 < $specificity || (0 === $specificity && $candidate['order'] >= $current['order']);
    }

    /**
     * Classes authored on anchors that became navigation-link blocks.
     *
     * Source provenance distinguishes an anchor-owned class from one authored on
     * the source `<li>`, whose `className` legitimately belongs on the item.
     *
     * @return array<string, true>
     */
    private function listNavigationAnchorClasses(array $sourceProvenance): array
    {
        $classes = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( ! in_array($entry['block_name'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
                continue;
            }

            foreach ( $this->navigationSourceOwnershipClasses($entry, 'anchor') as $candidate ) {
                if ( '' !== $candidate && ! str_starts_with($candidate, 'blocks-engine-') ) {
                    $classes[$candidate] = true;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    public function navigationColorInteractionStates(DOMElement $element): array
    {
        $matched = array();
        foreach ( $this->context->sourceStyles()->navigationStateRules() as $rule ) {
            if ( ! isset($rule['declarations']['color'])
                || ! $this->styleResolver->matchesCssSelector($element, $rule['base_selector'])
            ) {
                continue;
            }
            $matched[$rule['state']] = true;
        }

        return array_values(array_filter(
            array( 'hover', 'focus', 'focus-visible', 'active' ),
            static fn (string $state): bool => isset($matched[$state])
        ));
    }

    private function isAuthoredCurrentNavigationClass(string $className): bool
    {
        foreach ( preg_split('/[^a-z0-9]+/', strtolower($className)) ?: array() as $token ) {
            if ( in_array($token, array( 'active', 'current', 'selected' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restore icon-only navigation artwork core/navigation-link cannot save.
     *
     * The block keeps the accessible name as its label, so the recovered source
     * icon replaces that label visually while the name stays in the document.
     *
     * Tagged {@see CascadeLayer::SOURCE_STYLE_PROJECTION}.
     *
     * @return list<CascadeRule>
     */
    public function navigationLinkIconRules(string $serializedBlocks): array
    {
        $prefix = 'blocks-engine-navigation-link-icon-';
        if ( ! str_contains($serializedBlocks, $prefix)
            || ! preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s*(\{.*?\})\s*\/?-->/s', $serializedBlocks, $matches, PREG_SET_ORDER)
        ) {
            return array();
        }

        $rules = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class ) {
                if ( ! str_starts_with($class, $prefix) ) {
                    continue;
                }
                $declarations = $this->context->generatedSupportStyles()->navigationLinkIcon($class);
                if ( '' === $declarations ) {
                    continue;
                }
                $content = '.wp-block-navigation-item.' . $class . '>.wp-block-navigation-item__content';
                $rules[$class] = $content . '{' . $declarations . '}';
            }
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::SOURCE_STYLE_PROJECTION, $css),
            array_values($rules)
        );
    }

    /**
     * Restore a navigation icon beside a label core/navigation-link kept.
     *
     * Unlike {@see self::navigationLinkIconRules()}'s icon-only replacement,
     * the label stays visible; the icon is projected as a leading `::before`
     * mark so the anchor reads icon-then-label like its source.
     *
     * Tagged {@see CascadeLayer::SOURCE_STYLE_PROJECTION}.
     *
     * @return list<CascadeRule>
     */
    public function navigationLinkLeadingIconRules(string $serializedBlocks): array
    {
        $prefix = 'blocks-engine-navigation-link-leading-icon-';
        if ( ! str_contains($serializedBlocks, $prefix)
            || ! preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s*(\{.*?\})\s*\/?-->/s', $serializedBlocks, $matches, PREG_SET_ORDER)
        ) {
            return array();
        }

        $rules = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class ) {
                if ( ! str_starts_with($class, $prefix) ) {
                    continue;
                }
                $declarations = $this->context->generatedSupportStyles()->navigationLinkLeadingIcon($class);
                if ( '' === $declarations ) {
                    continue;
                }
                $content = '.wp-block-navigation-item.' . $class . '>.wp-block-navigation-item__content';
                $rules[$class] = $content . '::before{' . $declarations . '}';
            }
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::SOURCE_STYLE_PROJECTION, $css),
            array_values($rules)
        );
    }

    /**
     * Carry each navigation-link's resolved resting colour to the anchor core
     * renders. core/navigation-link does not consume style.color.text, while
     * adaptive header chrome can target the rendered anchor directly and beat
     * an inherited parent navigation colour.
     *
     * Tagged {@see CascadeLayer::SOURCE_STYLE_PROJECTION}.
     *
     * @return list<CascadeRule>
     */
    public function navigationLinkTextColorRules(string $serializedBlocks): array
    {
        $prefix = 'blocks-engine-navigation-link-color-';
        $currentPrefix = 'blocks-engine-navigation-current-color-';
        $statePrefix = 'blocks-engine-navigation-link-color-states-';
        if ( (! str_contains($serializedBlocks, $prefix) && ! str_contains($serializedBlocks, $currentPrefix))
            || ! preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s*(\{.*?\})\s*\/?-->/s', $serializedBlocks, $matches, PREG_SET_ORDER)
        ) {
            return array();
        }

        $rules = array();
        $currentColors = array();
        $defaultColors = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            $color = trim((string) ($attrs['style']['color']['text'] ?? ''));
            if ( '' === $color ) {
                foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class ) {
                    $fallbackColor = $this->context->generatedSupportStyles()->navigationLinkColor($class);
                    if ( '' !== $fallbackColor ) {
                        $color = $fallbackColor;
                        break;
                    }
                }
            }
            if ( '' === $color
                || preg_match('~[{}<>;]|/\*|(?:expression|url)\s*\(|javascript\s*:~i', $color)
                || array() === $this->styleResolver->cssDeclarations('color:' . $color)
            ) {
                continue;
            }

            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $stateMask = $this->navigationColorStateMaskFromClasses($classes, $statePrefix);
            if ( null === $stateMask ) {
                continue;
            }
            $expectedClass = $prefix . hash('sha256', $color . "\0" . $stateMask);
            $restingSuffix = $this->navigationColorRestingSuffix($stateMask);
            if ( in_array($expectedClass, $classes, true) ) {
                $selector = '.wp-block-navigation .wp-block-navigation-item.' . $expectedClass
                    . '>.wp-block-navigation-item__content' . $restingSuffix;
                $rules[$expectedClass] = $selector . '{color:' . $color . '}';
                $defaultColors[$expectedClass] = $color;
            }

            if ( in_array('blocks-engine-current-navigation-item', $classes, true) ) {
                $currentColors[$currentPrefix . hash('sha256', $color . "\0" . $stateMask)] = array(
                    'color' => $color,
                    'state_mask' => $stateMask,
                );
            }
        }

        if ( (array() !== $currentColors || array() !== $defaultColors)
            && preg_match_all('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $serializedBlocks, $navigationMatches, PREG_SET_ORDER)
        ) {
            foreach ( $navigationMatches as $navigationMatch ) {
                $attrs = json_decode($navigationMatch[1], true);
                if ( ! is_array($attrs) ) {
                    continue;
                }

                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                foreach ( $classes as $className ) {
                    if ( isset($defaultColors[$className]) && in_array('blocks-engine-native-responsive-navigation', $classes, true) ) {
                        $selector = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $className;
                        $rules['responsive:' . $className] = $selector . '>.wp-block-navigation__responsive-container-open,'
                            . $selector . ' .wp-block-navigation__responsive-container-close{color:' . $defaultColors[$className] . '}';
                    }
                    if ( ! isset($currentColors[$className]) ) {
                        continue;
                    }

                    $restingSuffix = $this->navigationColorRestingSuffix($currentColors[$className]['state_mask']);
                    $selector = '.wp-block-navigation.' . $className
                        . ' .wp-block-navigation-item.current-menu-item>.wp-block-navigation-item__content' . $restingSuffix
                        . ',.wp-block-navigation.' . $className
                        . ' .wp-block-navigation-item__content[aria-current]' . $restingSuffix;
                    $rules['current:' . $className] = $selector . '{color:' . $currentColors[$className]['color'] . '}';
                }
            }
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::SOURCE_STYLE_PROJECTION, $css),
            array_values($rules)
        );
    }

    /** @param list<string> $classes */
    private function navigationColorStateMaskFromClasses(array $classes, string $prefix): ?int
    {
        $masks = array();
        foreach ( $classes as $className ) {
            if ( ! str_starts_with($className, $prefix) ) {
                continue;
            }
            $value = substr($className, strlen($prefix));
            if ( ! ctype_digit($value) || 15 < (int) $value ) {
                return null;
            }
            $masks[(int) $value] = true;
        }

        if ( 1 < count($masks) ) {
            return null;
        }

        return array() === $masks ? 0 : (int) array_key_first($masks);
    }

    private function navigationColorRestingSuffix(int $stateMask): string
    {
        $suffix = '';
        foreach ( array( 'hover' => 1, 'focus' => 2, 'focus-visible' => 4, 'active' => 8 ) as $state => $bit ) {
            if ( 0 !== ($stateMask & $bit) ) {
                $suffix .= ':not(:' . $state . ')';
            }
        }

        return $suffix;
    }

    /**
     * Whether an authored selector's ancestor part names a promoted navigation
     * host, so a rule about a menu is not confused with one about a footer.
     *
     * @param array<string, true> $hostClasses
     */
    private function namesNavigationHost(string $ancestor, array $hostClasses): bool
    {
        if ( ! preg_match_all('/\.([A-Za-z_][A-Za-z0-9_-]*)/', $ancestor, $matches) ) {
            return false;
        }

        foreach ( $matches[1] as $candidate ) {
            if ( isset($hostClasses[$candidate]) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a class-only compound names nothing but classes the rendered
     * navigation-link items carry.
     *
     * @param array<string, true> $itemClasses
     */
    private function namesOnlyNavigationItemClasses(string $compound, array $itemClasses): bool
    {
        if ( ! preg_match_all('/\.([A-Za-z_][A-Za-z0-9_-]*)/', $compound, $matches) ) {
            return false;
        }

        foreach ( $matches[1] as $candidate ) {
            if ( ! isset($itemClasses[$candidate]) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every source anchor's own list item matches the item compound.
     *
     * @param list<DOMElement> $anchors
     */
    private function eachAnchorItemMatches(array $anchors, string $itemSelector): bool
    {
        foreach ( $anchors as $anchor ) {
            $item = $anchor->parentNode;
            if ( ! $item instanceof DOMElement || ! $this->styleResolver->matchesCssSelector($item, $itemSelector) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Classes carried by navigation-link items in the serialized output.
     *
     * @return array<string, true>
     */
    private function listNavigationItemClasses(string $serializedBlocks): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', $serializedBlocks, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $classes = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $candidate ) {
                if ( '' !== $candidate && ! str_starts_with($candidate, 'blocks-engine-') ) {
                    $classes[$candidate] = true;
                }
            }
        }

        return $classes;
    }

    /**
     * Classes carried by promoted list-navigation hosts in the serialized
     * output, as a lookup.
     *
     * @return array<string, true>
     */
    private function listNavigationHostClasses(string $serializedBlocks, bool $listOnly = true): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $serializedBlocks, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $classes = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            $className = (string) ($attrs['className'] ?? '');
            if ( $listOnly && ! str_contains($className, 'blocks-engine-list-navigation') ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $candidate ) {
                if ( '' !== $candidate && ! str_starts_with($candidate, 'blocks-engine-') ) {
                    $classes[$candidate] = true;
                }
            }
        }

        return $classes;
    }

    /**
     * The authored inline-axis margins of a rule, but only when at least one
     * side is `auto` — that is the declaration that positions a flex item, and
     * the one core's list reset destroys. The opposite side rides along so a
     * one-sided `auto` cannot be read as centring once both sides are restated.
     *
     * @param array<string, mixed> $declarations
     * @return array<string, string>
     */
    private function inlineAxisAutoMargins(array $declarations): array
    {
        $sides = array( 'left' => '', 'right' => '' );

        $shorthand = trim((string) ($declarations['margin'] ?? ''));
        if ( '' !== $shorthand ) {
            $parts = preg_split('/\s+/', $shorthand) ?: array();
            $count = count($parts);
            if ( 4 === $count ) {
                $sides['right'] = $parts[1];
                $sides['left'] = $parts[3];
            } elseif ( 2 === $count || 3 === $count ) {
                $sides['right'] = $parts[1];
                $sides['left'] = $parts[1];
            } elseif ( 1 === $count ) {
                $sides['right'] = $parts[0];
                $sides['left'] = $parts[0];
            }
        }

        foreach ( array( 'left' => array( 'margin-left', 'margin-inline-start' ), 'right' => array( 'margin-right', 'margin-inline-end' ) ) as $side => $properties ) {
            foreach ( $properties as $property ) {
                $value = trim((string) ($declarations[$property] ?? ''));
                if ( '' !== $value ) {
                    $sides[$side] = $value;
                }
            }
        }

        if ( 'auto' !== strtolower($sides['left']) && 'auto' !== strtolower($sides['right']) ) {
            return array();
        }

        $carried = array();
        foreach ( $sides as $side => $value ) {
            if ( '' !== $value ) {
                $carried[$side] = 'auto' === strtolower($value) ? 'auto' : $value;
            }
        }

        return $carried;
    }

    public function sourceMobileNavigationOverlayBackground(): string
    {
        $background = '';
        foreach ( array_merge($this->context->sourceStyles()->staticRules(), $this->context->sourceStyles()->conditionalRules()) as $rule ) {
            $selector = strtolower((string) ($rule['selector'] ?? ''));
            if ( ! str_contains($selector, 'nav') || ! preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', $selector) ) {
                continue;
            }

            $declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array();
            $candidate = trim((string) ($declarations['background-color'] ?? $declarations['background'] ?? ''));
            if ( '' !== $candidate && ! in_array(strtolower($candidate), array( 'none', 'transparent', 'inherit', 'initial', 'unset' ), true) ) {
                $background = $candidate;
            }
        }

        return $background;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function navigationSourceOwnershipClasses(array $entry, string $kind): array
    {
        $className = (string) ($entry['navigation_source_ownership'][$kind]['class_name'] ?? '');
        return array_values(array_filter(preg_split('/\s+/', trim($className)) ?: array()));
    }
}

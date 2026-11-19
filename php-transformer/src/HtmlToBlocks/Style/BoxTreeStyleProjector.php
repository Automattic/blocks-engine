<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/** Restyle an exact source-node carrier before provenance is discarded. */
final class BoxTreeStyleProjector
{
    private const SELECTOR = "\0carrier\0";

    /** The carrier class once, for declarations retained source state rules must still override ({@see self::inlineCarrier()}). */
    private const STATE_YIELDING_SELECTOR = "\0state-yielding\0";

    private readonly BoxTreeDeclarationPruner $pruner;

    public function __construct()
    {
        $this->pruner = new BoxTreeDeclarationPruner();
    }

    /**
     * @param string $blockName The block carrying the rule, whose WordPress base styles a pruned declaration falls back to.
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid Lifted author values: property => bands, widest
     *        first, each [max-width (null for the base rule), value] ({@see BoxTreeFluidIntent::lift()}).
     * @param array<string, array<string, list<array{0: float|null, 1: string}>>> $pseudoFluid The same, for the
     *        `before` and `after` pseudo-elements, by name ({@see BoxTreeFluidIntent::liftPseudo()}).
     * @param string $sourceTag The observed source element's tag name.
     * @param array{observations?: array<string, array<string, mixed>>, parents?: array<string, array<string, mixed>>, fluid?: array<string, list<array{0: float|null, 1: string}>>} $image
     *        The painting `<img>` inside an authored core/image `<figure>`, or the image of a core/media-text: its observations, its parent's, and its lifted author values.
     * @param array{observations?: array<string, array<string, mixed>>, parents?: array<string, array<string, mixed>>, fluid?: array<string, list<array{0: float|null, 1: string}>>, pseudoFluid?: array<string, array<string, list<array{0: float|null, 1: string}>>>} $summary
     *        The source `<summary>` of a core/details, the same way, plus its pseudo-elements' lifted author values.
     * @param bool $inlineSupports Whether invariant declarations may become block style attributes. A block whose
     *        saved markup is built by hand (and so never serializes them) keeps every declaration in its carrier.
     */
    public function project(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents = array(), string $blockName = '', array $fluid = array(), array $pseudoFluid = array(), string $sourceTag = '', array $image = array(), array $summary = array(), bool $inlineSupports = true): array
    {
        $attrs = $this->projectBlock($attrs, $observations, $viewports, $state, $parents, $blockName, $fluid, $pseudoFluid, $sourceTag, $image, $inlineSupports);
        $summaryObservations = $summary['observations'] ?? array();
        if ('core/details' !== $blockName || array() === $summaryObservations) return $attrs;
        // core/details saves a bare `<summary>`: the source summary's whole
        // observed box, pseudo-elements included, lands on that rendered
        // element through the block's carrier, like any source-node mapping.
        $selector = self::SELECTOR . ' > summary';
        return $this->projectRenderedElement($attrs, $summaryObservations, $viewports, $state, $summary['parents'] ?? array(), $summary['fluid'] ?? array(), false, $selector, 'core/details', 'summary', $summary['pseudoFluid'] ?? array(), $this->disclosureMarkerCss($summaryObservations, $viewports, $selector));
    }

    /**
     * WebKit paints a summary's disclosure triangle as its own
     * `::-webkit-details-marker`, which neither `list-style` nor `display`
     * reaches. Where the source summary shows no list-item marker (its
     * `list-style-type` is `none`, or it is not a list item) the author hid
     * that marker too, so it is hidden at the same captures.
     *
     * @param array<string, array<string, mixed>> $observations The source summary's, by viewport key.
     */
    private function disclosureMarkerCss(array $observations, array $viewports, string $selector): string
    {
        $keys = array_values(array_filter(array_map('strval', array_keys($observations)), static fn(string $key): bool => isset($viewports[$key]['width']) && is_array($observations[$key]['used_styles'] ?? null)));
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $css = '';
        $hidden = false;
        foreach ($keys as $index => $key) {
            $styles = $observations[$key]['used_styles'];
            $hides = 'none' === ($styles['list-style-type'] ?? null) || 'list-item' !== ($styles['display'] ?? 'list-item');
            if ($hides === $hidden) continue;
            $hidden = $hides;
            $rule = $this->rule($selector . '::-webkit-details-marker', array('display' => $hides ? 'none' : 'revert'));
            $css .= 0 === $index ? $rule : '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $rule . '}';
        }
        return $css;
    }

    /** {@see self::project()} for the block's own source element and an authored figure's image. */
    private function projectBlock(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents, string $blockName, array $fluid, array $pseudoFluid, string $sourceTag, array $image, bool $inlineSupports): array
    {
        $target = self::renderedElement($blockName, $attrs, $sourceTag);
        if (array() !== $observations) {
            $attrs = self::SELECTOR === $target
                ? $this->projectBox($attrs, $observations, $viewports, $state, $parents, $blockName, $fluid, $pseudoFluid, $inlineSupports)
                : $this->projectRenderedElement($attrs, $observations, $viewports, $state, $parents, $fluid, true, $target, $blockName, $sourceTag, $pseudoFluid);
        }
        $imageObservations = $image['observations'] ?? array();
        if (!in_array($blockName, array('core/image', 'core/media-text'), true) || array() === $imageObservations) return $attrs;
        return $this->projectRenderedElement($attrs, $imageObservations, $viewports, $state, $image['parents'] ?? array(), $image['fluid'] ?? array(), false, self::renderedElement($blockName, $attrs, 'img'), $blockName, 'img');
    }

    /** One carrier for the block's own box, from its source element's observations. */
    private function projectBox(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents, string $blockName, array $fluid, array $pseudoFluid = array(), bool $inlineSupports = true): array
    {
        $keys = array_keys($observations);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        $tagName = self::emittedTag($blockName, $attrs);
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $status[$key] = $this->pruner->classify($observations[$key]['used_styles'], is_array($parent) ? $parent : null, $blockName, $tagName);
            $declarations[$key] = $this->kept($observations[$key]['used_styles'], $status[$key]);
        }
        $bands = $this->liftFluid($declarations, $keys, $fluid, $observations, $parents, $blockName, $tagName);
        $baseKey = $keys[0];
        $base = $declarations[$baseKey];
        $consumed = $inlineSupports ? $this->mapInlineSupports($attrs, $base, $keys, $observations, $blockName) : array();
        $css = $this->carrierCss(array_diff_key($base, $consumed), $keys, $declarations, $status, $observations, $viewports, $bands, true, $parents, self::syntheticAnchorCarrier($blockName, $attrs), $pseudoFluid);
        $class = $this->carrier($css, $state);
        if ('' === $class) return $attrs;
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
    }

    /**
     * Move the invariant base declarations a block support can carry into its
     * style attributes; returns the declarations those attributes now own.
     *
     * @param array<string, mixed> $attrs
     * @param array<string, string> $base
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function mapInlineSupports(array &$attrs, array $base, array $keys, array $observations, string $blockName): array
    {
        // A block the runtime has no metadata for (a generated block, whose
        // save builds its markup by hand) declares no style supports, so it
        // would never serialize a style attribute: the carrier keeps them all.
        if ('' !== $blockName && null === self::runtime()->blockMetadata($blockName)) return array();
        $baseKey = $keys[0];
        $mapper = new StyleAttributeMapper();
        $existing = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $existingDeclarations = SourceDeclarations::parse($mapper->serialize($existing)['style']);
        // Never resurrect authored values for observed (including inherited) properties.
        $unobserved = array_diff_key($existingDeclarations, $observations[$baseKey]['used_styles']);
        // Block attributes serialize inline and beat every @media carrier rule,
        // so only a value observed identically in every viewport may become one.
        // A varying property stays entirely in the carrier: base rule plus deltas.
        $invariant = array_filter($base, static function (string $value, string $property) use ($keys, $observations): bool {
            foreach ($keys as $key) {
                if (($observations[$key]['used_styles'][$property] ?? null) !== $value) return false;
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH);
        $mapped = $mapper->map(array_replace($unobserved, $invariant));
        // A style group the block cannot serialize (a paragraph's shadow) would
        // be dropped from its attributes: it stays in the carrier instead.
        if ('' !== $blockName) {
            $mapped['style'] = self::runtime()->normalizeBlockSupportAttributes($blockName, array('style' => $mapped['style']))['attrs']['style'] ?? array();
        }
        $attrs['style'] = $mapped['style'];
        $attrs = array_replace($attrs, $mapped['attrs']);
        // Layout declarations are deliberately excluded by StyleAttributeMapper;
        // the captured layout belongs to the engine rule, not a core layout guess.
        // Border shorthands can serialize as longhands. Retain their captured
        // declarations unless the mapper round-trip represents the same key.
        return SourceDeclarations::parse($mapper->serialize($mapped['style'])['style']);
    }

    private static function runtime(): Runtime
    {
        static $runtime = null;
        return $runtime ??= new Runtime();
    }

    /**
     * A source node saved inside a core wrapper owns every declaration on
     * the mapped descendant, including pseudo-elements and media variants.
     * Synthetic wrappers generate no box. An authored wrapper keeps its own
     * independently projected box. Native image dimensions must also yield
     * to the carrier rather than overriding its responsive declarations.
     *
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     * @param bool $synthetic Whether the carrier host exists only for core markup.
     * @param string $extraCss Carrier rules for the element that no observed longhand expresses.
     */
    private function projectRenderedElement(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents, array $fluid, bool $synthetic, string $selector, string $blockName, string $sourceTag, array $pseudoFluid = array(), string $extraCss = ''): array
    {
        $keys = array_keys($observations);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $status[$key] = $this->pruner->classify($observations[$key]['used_styles'], is_array($parent) ? $parent : null, $blockName, $sourceTag);
            $declarations[$key] = $this->kept($observations[$key]['used_styles'], $status[$key]);
        }
        $bands = $this->liftFluid($declarations, $keys, $fluid, $observations, $parents, $blockName, $sourceTag);
        if ($synthetic) {
            // Existing presentation belongs to the descendant too, but must
            // never resurrect an observed property pruned from the evidence.
            $mapper = new StyleAttributeMapper();
            $existing = SourceDeclarations::parse($mapper->serialize(is_array($attrs['style'] ?? null) ? $attrs['style'] : array())['style']);
            $declarations[$keys[0]] = array_replace(array_diff_key($existing, $observations[$keys[0]]['used_styles']), $declarations[$keys[0]]);
            // Descendant carriers own all presentation; inline supports would
            // otherwise style the synthetic wrapper (or defeat media variants).
            $attrs['style'] = array();
            if ('core/button' === $blockName) {
                // These support rules deliberately move layout to the wrapper
                // and force the link inline with !important. The source-node
                // carrier now replaces that legacy ownership contract.
                $attrs['className'] = implode(' ', array_filter(SourceDom::tokens((string) ($attrs['className'] ?? '')), static fn(string $class): bool => !str_starts_with($class, 'blocks-engine-layout-child-button')));
            }
        }
        foreach ('core/image' === $blockName ? self::IMAGE_DIMENSION_ATTRIBUTES : array() as $attribute => $property) {
            if (isset($observations[$keys[0]]['used_styles'][$property])) unset($attrs[$attribute]);
        }
        // `is-resized` marks native image dimensions the carrier now owns.
        if ('core/image' === $blockName && !isset($attrs['width']) && !isset($attrs['height'])) {
            $attrs['className'] = implode(' ', array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), static fn(string $class): bool => '' !== $class && 'is-resized' !== $class));
            if ('' === $attrs['className']) unset($attrs['className']);
        }
        $split = $synthetic ? $this->outerBoxSplit($blockName, $keys, $declarations, $bands, $observations, $parents, $selector) : null;
        $css = $this->carrierCss($declarations[$keys[0]], $keys, $declarations, $status, $observations, $viewports, $bands, true, $parents, false, $pseudoFluid, $selector, $split);
        // An inline anchor carrier keeps the inline box EngineSupportCss gives
        // it: a boxless paragraph is a RichText root the editor cannot focus,
        // so text typed into it never reaches the block (issue #5797). Around
        // a source inline link, an unpadded inline box lays out the same line.
        $boxless = $synthetic && null === $split
            && !in_array(SourceBlockAttributeProjector::INLINE_ANCHOR_CARRIER_CLASS, SourceDom::tokens((string) ($attrs['className'] ?? '')), true);
        $wrapper = $boxless ? $this->rule(self::SELECTOR, array('display' => 'contents')) : '';
        $class = $this->carrier($wrapper . $css . $extraCss, $state);
        if ('' === $class) return $attrs;
        if ($synthetic) $state->markBoxlessImageFigureCarrier($class);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
    }

    /**
     * Properties that place a box in its parent's flow rather than style its
     * contents, by their initial values. They belong on the block's outermost
     * rendered box, which is the flex or grid item (or the margin box) in
     * the copy's parent.
     */
    private const OUTER_BOX = array(
        'margin-top' => array('0px', '0'), 'margin-right' => array('0px', '0'), 'margin-bottom' => array('0px', '0'), 'margin-left' => array('0px', '0'),
        'align-self' => array('auto', 'normal'), 'justify-self' => array('auto', 'normal'), 'order' => array('0'),
        'flex-grow' => array('0'), 'flex-shrink' => array('1'), 'flex-basis' => array('auto'),
        'grid-column-start' => array('auto'), 'grid-column-end' => array('auto'), 'grid-row-start' => array('auto'), 'grid-row-end' => array('auto'),
        'position' => array('static'), 'top' => array('auto'), 'right' => array('auto'), 'bottom' => array('auto'), 'left' => array('auto'),
        'z-index' => array('auto'), 'float' => array('none'), 'clear' => array('none'),
    );

    /** Sizes that size the flex or grid item, so they follow it onto the outer box. */
    private const OUTER_ITEM_SIZE = array('width' => array('auto'), 'min-width' => array('auto', '0px', '0'), 'max-width' => array('none'));

    /**
     * How a core/buttons or core/button carrier splits each declaration set
     * between the block's outermost rendered box and the source-node link,
     * or null when no outer-box property differs from its initial value at
     * any capture or band (then the wrappers stay transparent and the link
     * owns everything).
     *
     * A core/buttons whose source is the link itself owns the outer box
     * (`div.wp-block-buttons`); its core/button child carries the same
     * evidence, so the inner wrapper is made transparent and its outer-box
     * declarations are reset. A core/button whose core/buttons has its own
     * source box owns `div.wp-block-button` as its outer box.
     *
     * @param list<string> $keys Viewport keys, widest first.
     * @return (\Closure(array<string, string>): string)|null
     */
    private function outerBoxSplit(string $blockName, array $keys, array $declarations, array $bands, array $observations, array $parents, string $selector): ?\Closure
    {
        if (!in_array($blockName, array('core/buttons', 'core/button'), true)) return null;
        $baseKey = $keys[0];
        $parentDisplay = (string) ($parents[$baseKey]['used_styles']['display'] ?? '');
        $item = 1 === preg_match('/(?<![\w-])(?:inline-)?(?:flex|grid)(?![\w-])/', $parentDisplay);
        // Outside a layout item there is no wrapper box to place. Keep
        // positioning (including fixed offsets) on the source link itself.
        if (!$item) return null;
        $outer = $item ? self::OUTER_BOX + self::OUTER_ITEM_SIZE : self::OUTER_BOX;
        $placed = array();
        $declaredOuter = array();
        foreach (array_merge(array_values($declarations), array_values($bands)) as $declared) {
            foreach (array_intersect_key($declared, $outer) as $property => $value) {
                $declaredOuter[$property] = true;
                if (!in_array(strtolower(trim($value)), $outer[$property], true)) $placed[$property] = true;
            }
        }
        if (array() === $placed) return null;
        $observed = $observations[$baseKey]['used_styles'];
        // The link's border box fills the outer box: a content-box width
        // grows by the link's own horizontal padding and border.
        $edges = 0.0;
        if ('border-box' !== ($observed['box-sizing'] ?? 'content-box')) {
            foreach (array('padding-left', 'padding-right', 'border-left', 'border-right') as $edge) {
                if (preg_match('/^(-?(?:\d*\.)?\d+)px\b/', (string) ($observed[$edge] ?? ''), $match)) $edges += (float) $match[1];
            }
        }
        $shared = 'core/buttons' === $blockName;
        $first = true;
        $hidden = false;
        return function (array $declared) use ($outer, $declaredOuter, $edges, $shared, $selector, &$first, &$hidden): string {
            $outerDeclared = array_intersect_key($declared, $outer);
            $inner = array_diff_key($declared, $outer);
            // A hidden link must not leave its outer box's margins behind.
            if (isset($inner['display']) && ('none' === $inner['display']) !== $hidden) {
                $hidden = 'none' === $inner['display'];
                $outerDeclared = array('display' => $hidden ? 'none' : 'flex') + $outerDeclared;
            }
            foreach (array_keys(self::OUTER_ITEM_SIZE) as $size) {
                if (!isset($outerDeclared[$size]) || in_array(strtolower(trim($outerDeclared[$size])), $outer[$size], true)) continue;
                if (0.0 !== $edges) $outerDeclared[$size] = 'calc(' . $outerDeclared[$size] . ' + ' . self::width($edges) . 'px)';
                // The flex outer box is sized; its only item fills it.
                if ('width' === $size) $inner['flex-grow'] = '1';
            }
            $css = '';
            if ($first) {
                $first = false;
                $box = $shared ? array() : array('display' => 'flex');
                $css .= $this->rule(self::SELECTOR, $outerDeclared + $box);
                if ($shared) {
                    $resets = array('display' => 'contents');
                    foreach (array_keys($declaredOuter) as $property) $resets[$property] = 'initial';
                    $css .= $this->rule(self::SELECTOR . ' > .wp-block-button', $resets);
                }
            } else {
                $css .= $this->rule(self::SELECTOR, $outerDeclared);
            }
            return $css . $this->rule($selector, $inner);
        };
    }

    /** Map source identity once, before any captured, author or pseudo rule is emitted. */
    private static function renderedElement(string $blockName, array $attrs, string $sourceTag): string
    {
        $sourceTag = strtolower($sourceTag);
        if ('core/image' === $blockName && 'img' === $sourceTag) return self::SELECTOR . ' img';
        // core/media-text saves its image bare in the media pane, where core
        // sizes it to the pane (`width:100%`).
        if ('core/media-text' === $blockName && 'img' === $sourceTag) return self::SELECTOR . ' > .wp-block-media-text__media img';
        // core/button saves its link as `<button>` when its tagName says so,
        // and a core/buttons wrapper cannot see which element its source
        // `<button>` was saved as. A carrier aimed at `a.wp-block-button__link`
        // would then style nothing and leave the control's source presentation
        // (an opacity:0 skip button, #5860) behind, so a button-sourced
        // carrier names the link by its class alone.
        $link = 'button' === ($attrs['tagName'] ?? '') || ('core/buttons' === $blockName && 'button' === $sourceTag) ? '.wp-block-button__link' : 'a.wp-block-button__link';
        if ('core/button' === $blockName) return self::SELECTOR . ' > ' . $link;
        if ('core/buttons' === $blockName && in_array($sourceTag, array('a', 'button'), true)) return self::SELECTOR . ' > .wp-block-button > ' . $link;
        // Only a link survives RichText as the paragraph's own child: a span
        // is lowered to a `<mark>` carrier or unwrapped, so a `> span` rule
        // would match nothing and the transparent paragraph would leave its
        // text on the parent's font and line box. The paragraph is its box.
        if ('core/paragraph' === $blockName && 'a' === $sourceTag
            && in_array(SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS, SourceDom::tokens((string) ($attrs['className'] ?? '')), true)) return self::SELECTOR . ' > a';
        return self::SELECTOR;
    }

    /** core/image attributes serialized inline on its `<img>` => the observed property each sets. */
    private const IMAGE_DIMENSION_ATTRIBUTES = array('width' => 'width', 'height' => 'height', 'aspectRatio' => 'aspect-ratio', 'scale' => 'object-fit');

    /**
     * Restyle an inline element inside RichText, which has no block
     * attributes: its observed typography that differs from its source
     * parent's goes entirely to a carrier class. A value equal to the
     * parent's is left to inheritance, so a lifted fluid block value keeps
     * reaching it between captures.
     *
     * An element with a user-agent colour of its own (a link, a mark) also
     * carries its observed colour, whether the source inherited it or set
     * it: it never inherits its parent's, so left out, a link paints the
     * browser's blue. That colour is the resting state only, so it is
     * declared at single-class specificity: above the user agent and
     * theme.json's `a:where(...)` element rule, below the source's retained
     * `a:hover`, `:focus` and `:visited` rules and theme.json's link states,
     * each at least a pseudo-class plus a type or class.
     *
     * @param string $tagName The emitted inline element's tag, for its user-agent defaults.
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     * @return string The carrier class, or '' when the inline inherits everything observed.
     */
    public function inlineCarrier(array $observations, array $viewports, LayoutGeometryState $state, array $parents, array $fluid = array(), string $tagName = ''): string
    {
        $observations = array_filter($observations, static fn(mixed $observation, string|int $key): bool => isset($viewports[$key]['width']) && is_array($observation['used_styles'] ?? null), ARRAY_FILTER_USE_BOTH);
        if (array() === $observations) return '';
        $yielding = BoxTreeDeclarationPruner::ownsDefault($tagName, 'color') ? array('color' => true) : array();
        // A link the source left at the browser's colour keeps it, with the
        // browser's `:visited` and `:active` colours, by declaring none.
        if ('a' === strtolower($tagName) && array(self::UA_LINK_COLOR) === array_values(array_unique(array_map(static fn(array $observation): string => (string) ($observation['used_styles']['color'] ?? ''), $observations)))) $yielding = array();
        $properties = array_flip(self::INLINE_TYPOGRAPHY) + $yielding;
        $typography = array();
        foreach ($observations as $key => $observation) $typography[$key] = array('used_styles' => array_intersect_key($observation['used_styles'], $properties));
        $keys = array_keys($typography);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $parent = is_array($parent) ? $parent : null;
            $used = $typography[$key]['used_styles'];
            $status[$key] = $this->pruner->classify(array_diff_key($used, $yielding), $parent) + $this->pruner->classify(array_intersect_key($used, $yielding), $parent, '', $tagName);
            $declarations[$key] = $this->kept($used, $status[$key]);
        }
        $bands = $this->liftFluid($declarations, $keys, array_intersect_key($fluid, $properties), $typography, $parents);
        $split = array() === $yielding ? null : fn(array $declared): string => $this->rule(self::SELECTOR, array_diff_key($declared, $yielding)) . $this->rule(self::STATE_YIELDING_SELECTOR, array_intersect_key($declared, $yielding));
        return $this->carrier($this->carrierCss($declarations[$keys[0]], $keys, $declarations, $status, $typography, $viewports, $bands, false, $parents, false, array(), self::SELECTOR, $split), $state);
    }

    /**
     * Restyle an observed element the output keeps as source markup inside a
     * block (a navigation label's box, an authored control's icon artwork):
     * it has no block attributes and is not a RichText inline, so its whole
     * observed box goes to one carrier class, exactly as a block's would,
     * against its source parent's observations. Without it such an element
     * keeps only its source classes, whose author rules an evidence route
     * does not ship.
     *
     * @param string $tagName The emitted (source) element's tag, for UA defaults.
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     * @param array<string, array<string, list<array{0: float|null, 1: string}>>> $pseudoFluid {@see BoxTreeFluidIntent::liftPseudo()}, by name.
     * @return string The carrier class, or '' when nothing observed differs from what the element gets anyway.
     */
    public function elementCarrier(array $observations, array $viewports, LayoutGeometryState $state, array $parents, string $tagName, array $fluid = array(), array $pseudoFluid = array()): string
    {
        $observations = array_filter($observations, static fn(mixed $observation, string|int $key): bool => isset($viewports[$key]['width']) && is_array($observation['used_styles'] ?? null), ARRAY_FILTER_USE_BOTH);
        if (array() === $observations) return '';
        $keys = array_map('strval', array_keys($observations));
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $status[$key] = $this->pruner->classify($observations[$key]['used_styles'], is_array($parent) ? $parent : null, '', $tagName);
            $declarations[$key] = $this->kept($observations[$key]['used_styles'], $status[$key]);
        }
        $bands = $this->liftFluid($declarations, $keys, $fluid, $observations, $parents, '', $tagName);
        return $this->carrier($this->carrierCss($declarations[$keys[0]], $keys, $declarations, $status, $observations, $viewports, $bands, true, $parents, false, $pseudoFluid), $state);
    }

    /**
     * Restyle a source box that becomes no block, such as the document body
     * the destination document supplies, under a fixed selector. Each given
     * property is restated where lifted author intent, or one value observed
     * at every capture, holds at every width and differs from its initial
     * value: a varying pixel value would freeze at one capture, with no block
     * to band it.
     *
     * @param array<string, string> $properties Property => its initial value, which is not restated.
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     */
    public function boxRule(string $selector, array $properties, array $observations, array $fluid = array()): string
    {
        $base = array();
        $bands = array();
        foreach ($properties as $property => $initial) {
            $lifted = $fluid[$property] ?? array();
            if (array() !== $lifted && null === $lifted[0][0]) {
                if ($initial !== $lifted[0][1] || 1 < count($lifted)) $base[$property] = $lifted[0][1];
                foreach (array_slice($lifted, 1) as [$maxWidth, $value]) $bands[self::width((float) $maxWidth)][$property] = $value;
                continue;
            }
            $values = array_unique(array_map(static fn(array $observation): string => (string) ($observation['used_styles'][$property] ?? ''), $observations));
            if (1 === count($values) && !in_array(reset($values), array('', $initial), true)) $base[$property] = reset($values);
        }
        $css = $this->rule($selector, $base);
        uksort($bands, static fn(int|string $a, int|string $b): int => (float) $b <=> (float) $a);
        foreach ($bands as $maxWidth => $declared) $css .= '@media (max-width:' . $maxWidth . 'px){' . $this->rule($selector, $declared) . '}';
        return $css;
    }

    /** Keyword properties BoxTreeFluidIntent lifts only when they explain every capture, pruned or not. */
    private const SWITCHED_KEYWORDS = array('display' => true, 'visibility' => true);

    /**
     * Grid lines BoxTreeFluidIntent lifts only from author winners at every
     * capture: a capture pruned at the initial `auto` is still explained, so
     * a placement switching to `auto` follows the author's breakpoint. Lines
     * every capture pruned (not a grid item) stay out.
     */
    private const GRID_LINES = array('grid-row-start' => true, 'grid-row-end' => true, 'grid-column-start' => true, 'grid-column-end' => true);

    /**
     * Heights BoxTreeFluidIntent lifts as `auto` when no author rule sizes the
     * box at any width. A captured pixel height is then what layout made of
     * the box (grid or flex stretch, row sizing, content), not author intent:
     * the lift replaces it at every capture, including those that pruned the
     * initial `auto`, so no capture's used height freezes as a floor or a
     * fixed size for the widths between captures.
     */
    private const LAYOUT_HEIGHTS = array('height' => true, 'min-height' => true);

    /** The user agent's unvisited link colour, as evidence serializes it. */
    private const UA_LINK_COLOR = 'rgb(0, 0, 238)';

    /**
     * Inherited typography an inline can set apart from its block. Colour
     * joins only for an element that computes its own ({@see self::inlineCarrier()}).
     */
    private const INLINE_TYPOGRAPHY = array('font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform');

    /**
     * Author intent proven at every capture (BoxTreeFluidIntent) replaces
     * the frozen pixels in every capture, so it needs no capture delta;
     * where the author switches it, its bands follow the author's own
     * breakpoints. Do not resurrect properties pruned at any viewport, except
     * a switched keyword (`visibility`) whose lift explains every capture,
     * pruned ones included: without its base value a narrower band could
     * not switch it back.
     *
     * A lifted base value that restates the property's initial value (or
     * cannot apply to the box) in every capture's context is not declared at
     * all, exactly as the pruner drops an observed one. With a narrower band
     * the base stays, so the cascade within the carrier stays explicit.
     *
     * @param array<string, array<string, string>> $declarations Kept declarations by viewport key, updated in place.
     * @param list<string> $keys Viewport keys, widest first.
     * @param array<string, array<string, mixed>> $observations The box's observations, by viewport key.
     * @param array<string, array<string, mixed>> $parents Its parent's observations, by viewport key.
     * @return array<string, array<string, string>> Band declarations by max-width.
     */
    private function liftFluid(array &$declarations, array $keys, array $fluid, array $observations = array(), array $parents = array(), string $blockName = '', string $tagName = ''): array
    {
        $bands = array();
        $unbanded = array();
        foreach ($fluid as $property => $propertyBands) {
            if (array() === $propertyBands || null !== $propertyBands[0][0]) continue;
            $kept = 0;
            $layoutHeight = isset(self::LAYOUT_HEIGHTS[$property]) && array() === array_diff(array_column($propertyBands, 1), array('auto'));
            foreach ($keys as $key) {
                if (isset($declarations[$key][$property])) {
                    ++$kept;
                    continue;
                }
                if (!isset(self::SWITCHED_KEYWORDS[$property]) && !isset(self::GRID_LINES[$property]) && !$layoutHeight) continue 2;
            }
            if (0 === $kept && (isset(self::GRID_LINES[$property]) || $layoutHeight)) continue;
            foreach ($keys as $key) $declarations[$key][$property] = $propertyBands[0][1];
            if (1 === count($propertyBands) && 'display' !== $property) $unbanded[] = $property;
            foreach (array_slice($propertyBands, 1) as [$maxWidth, $value]) {
                $bands[self::width((float) $maxWidth)][$property] = $value;
            }
        }
        if (array() === $unbanded || array() === $observations) return $bands;
        $redundant = array_fill_keys($unbanded, true);
        foreach ($keys as $key) {
            $used = $observations[$key]['used_styles'] ?? null;
            if (!is_array($used)) return $bands;
            $parent = $parents[$key]['used_styles'] ?? null;
            $status = $this->pruner->classifyLifted(array_replace($used, $declarations[$key]), is_array($parent) ? $parent : null, array_keys($redundant), $blockName, $tagName);
            foreach (array_keys($redundant) as $property) {
                $reason = $status[$property] ?? BoxTreeDeclarationPruner::KEPT;
                if (BoxTreeDeclarationPruner::INITIAL !== $reason && BoxTreeDeclarationPruner::NOT_APPLICABLE !== $reason) unset($redundant[$property]);
            }
        }
        foreach ($keys as $key) $declarations[$key] = array_diff_key($declarations[$key], $redundant);
        return $bands;
    }

    /**
     * The base rule, then each narrower capture's delta, then the lifted bands.
     *
     * @param array<string, array<string, mixed>> $parents The block parent's observations, by viewport key.
     * @param array<string, array<string, list<array{0: float|null, 1: string}>>> $pseudoFluid Lifted pseudo-element values, by name.
     */
    private function carrierCss(array $base, array $keys, array $declarations, array $status, array $observations, array $viewports, array $bands, bool $pseudos, array $parents = array(), bool $syntheticAnchor = false, array $pseudoFluid = array(), string $selector = self::SELECTOR, ?\Closure $split = null): string
    {
        $baseKey = $keys[0];
        $rule = function (array $declared) use ($syntheticAnchor, $selector, $split): string {
            if (null !== $split) return $split($declared);
            $css = $this->rule($selector, $declared);
            if ($syntheticAnchor && isset($declared['display'])) {
                // A real carrier box owns the margin. Only a transparent
                // carrier passes it to the link, including at breakpoints.
                $css .= $selector . '>a{margin:' . ('contents' === $declared['display'] ? 'inherit' : '0') . '}';
            }
            return $css;
        };
        $css = $rule($base);
        $effective = $declarations[$baseKey];
        $pseudoEffective = array();
        $pseudoBands = array();
        $pseudoLifted = $pseudos ? $this->liftPseudoFluid($observations, $keys, $pseudoFluid, $pseudoBands) : array();
        if ($pseudos) $css .= $this->pseudoRules($observations[$baseKey], $pseudoEffective, $pseudoLifted, $selector);
        foreach (array_slice($keys, 1) as $index => $key) {
            $delta = $this->delta($effective, $declarations[$key], $status[$key], $observations[$key]['used_styles']);
            // A box hidden across this rule's whole range renders none of the
            // rest, and one inside a parent hidden at this capture is not
            // rendered there, so its captured values are no layout evidence:
            // only its display is restated, and narrower captures restate
            // against the rules that still apply.
            $narrower = $keys[$index + 2] ?? null;
            if ('none' === ($parents[$key]['used_styles']['display'] ?? null) || $this->hiddenThroughout(array_replace($effective, $delta)['display'] ?? null, $observations[$key]['used_styles']['display'] ?? null, (float) $viewports[$key]['width'], null === $narrower ? 0.0 : (float) $viewports[$narrower]['width'], $bands)) {
                $delta = array_intersect_key($delta, array('display' => true));
            }
            $effective = array_replace($effective, $delta);
            $pseudo = $pseudos ? $this->pseudoRules($observations[$key], $pseudoEffective, $pseudoLifted, $selector) : '';
            if (array() === $delta && '' === $pseudo) continue;
            $css .= '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $rule($delta) . $pseudo . '}';
        }
        // Numeric keys become ints: compare them as widths, narrowest last.
        $widths = array_keys($bands + $pseudoBands);
        usort($widths, static fn(int|string $a, int|string $b): int => (float) $b <=> (float) $a);
        foreach ($widths as $maxWidth) {
            $rules = isset($bands[$maxWidth]) ? $rule($bands[$maxWidth]) : '';
            foreach ($pseudoBands[$maxWidth] ?? array() as $name => $declared) $rules .= $this->rule($selector . '::' . $name, $declared);
            if ('' !== $rules) $css .= '@media (max-width:' . $maxWidth . 'px){' . $rules . '}';
        }
        return $css;
    }

    /**
     * Pseudo-element author intent ({@see BoxTreeFluidIntent::liftPseudo()})
     * lifts like the host's ({@see self::liftFluid()}): only a property the
     * pseudo renders and keeps at every capture, so no pruned value is
     * resurrected; anything else keeps its captured pixels.
     *
     * @param list<string> $keys Viewport keys, widest first.
     * @param array<int|string, array<string, array<string, string>>> $bands Band declarations by max-width, then pseudo name; filled in.
     * @return array<string, array<string, string>> Lifted base value by pseudo name, then property.
     */
    private function liftPseudoFluid(array $observations, array $keys, array $pseudoFluid, array &$bands): array
    {
        $lifted = array();
        foreach (array('before', 'after') as $name) {
            $fluid = $pseudoFluid[$name] ?? array();
            if (array() === $fluid) continue;
            $kept = array();
            foreach ($keys as $key) {
                $pseudo = $observations[$key]['pseudo'][$name] ?? null;
                if (!is_array($pseudo['used_styles'] ?? null)) continue 2;
                $kept[$key] = $this->kept($pseudo['used_styles'], $this->pruner->classify($pseudo['used_styles'], $observations[$key]['used_styles']));
            }
            foreach ($fluid as $property => $propertyBands) {
                if (array() === $propertyBands || null !== $propertyBands[0][0]) continue;
                foreach ($keys as $key) {
                    if (!isset($kept[$key][$property])) continue 2;
                }
                $lifted[$name][$property] = $propertyBands[0][1];
                foreach (array_slice($propertyBands, 1) as [$maxWidth, $value]) {
                    $bands[self::width((float) $maxWidth)][$name][$property] = $value;
                }
            }
        }
        return $lifted;
    }

    /**
     * Whether a capture's rule, which applies from its own width down to the
     * next narrower capture's, only ever lands on a `display:none` box. The
     * lifted bands come after the capture rules, so a band that declares
     * display wins wherever it applies.
     *
     * @param array<int|string, array<string, string>> $bands Band declarations by max-width.
     */
    private function hiddenThroughout(?string $display, ?string $observed, float $width, float $narrower, array $bands): bool
    {
        if ('none' !== $observed) return false;
        $switches = array_filter($bands, static fn(array $declared): bool => isset($declared['display']));
        if (array() === $switches) return 'none' === $display;
        // The cascade only changes at a band's own max-width.
        $points = array($width);
        foreach (array_keys($switches) as $maxWidth) {
            if ((float) $maxWidth > $narrower && (float) $maxWidth < $width) $points[] = (float) $maxWidth;
        }
        foreach ($points as $point) {
            $winner = $display;
            $narrowest = INF;
            foreach ($switches as $maxWidth => $declared) {
                if ((float) $maxWidth >= $point && (float) $maxWidth < $narrowest) {
                    $narrowest = (float) $maxWidth;
                    $winner = $declared['display'];
                }
            }
            if ('none' !== $winner) return false;
        }
        return true;
    }

    /** Register one carrier per distinct declaration set, not per source node. */
    private function carrier(string $css, LayoutGeometryState $state): string
    {
        if ('' === $css) return '';
        $class = $state->allocateCarrier('box-tree:' . $css);
        // Outrank core block/layout selectors without defeating editor inline styles.
        $state->registerRule($class, str_replace(array(self::SELECTOR, self::STATE_YIELDING_SELECTOR), array('.' . $class . '.' . $class, '.' . $class), $css));
        return $class;
    }

    /**
     * Declarations a narrower viewport adds over the rules that already apply
     * to it. A longhand the wider rules declare but this viewport prunes is
     * reset: inherited values to `inherit`, initial values to the observed
     * value, since omission would leave the wider value active.
     */
    private function delta(array $effective, array $declarations, array $status, array $observed): array
    {
        $delta = array();
        foreach ($declarations as $property => $value) {
            if (($effective[$property] ?? null) !== $value) $delta[$property] = $value;
        }
        foreach (array_diff_key($effective, $declarations) as $property => $_) {
            $reason = $status[$property] ?? BoxTreeDeclarationPruner::NOT_APPLICABLE;
            if (BoxTreeDeclarationPruner::INHERITED === $reason && 'inherit' !== $effective[$property]) $delta[$property] = 'inherit';
            elseif (BoxTreeDeclarationPruner::INITIAL === $reason && $effective[$property] !== $observed[$property]) $delta[$property] = $observed[$property];
        }
        return $delta;
    }

    /** Synthetic paragraphs use the transparent-link margin fallback in EngineSupportCss. */
    private static function syntheticAnchorCarrier(string $blockName, array $attrs): bool
    {
        $classes = preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array();
        if ('core/paragraph' !== $blockName || !in_array(SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS, $classes, true)
            || in_array(SourceBlockAttributeProjector::SOURCE_BOX_PARAGRAPH_CLASS, $classes, true)) return false;
        return true;
    }

    private static function width(float $width): string
    {
        return rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.');
    }

    /** The element a block's carrier class lands on, when the block fixes it. */
    private static function emittedTag(string $blockName, array $attrs): string
    {
        return match ($blockName) {
            'core/heading' => 'h' . max(1, min(6, (int) ($attrs['level'] ?? 2))),
            'core/post-title', 'core/query-title' => 'h' . max(1, min(6, (int) ($attrs['level'] ?? ('core/post-title' === $blockName ? 2 : 1)))),
            'core/group' => strtolower((string) ($attrs['tagName'] ?? 'div')),
            'core/paragraph' => 'p',
            'core/list' => ! empty($attrs['ordered']) ? 'ol' : 'ul',
            'core/list-item' => 'li',
            'core/code', 'core/preformatted', 'core/verse' => 'pre',
            'core/quote' => 'blockquote',
            default => '',
        };
    }

    private function kept(array $styles, array $status): array
    {
        return array_filter($styles, static fn(string $property): bool => BoxTreeDeclarationPruner::KEPT === ($status[$property] ?? null), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, array<string, string>> $effective Pseudo declarations already active, by pseudo name.
     * @param array<string, array<string, string>> $lifted Lifted base values by pseudo name ({@see self::liftPseudoFluid()}).
     */
    private function pseudoRules(array $observation, array &$effective, array $lifted = array(), string $selector = self::SELECTOR): string
    {
        $css = '';
        foreach (array('before', 'after') as $name) {
            $pseudo = $observation['pseudo'][$name] ?? null;
            if (null !== $pseudo) {
                // Pseudos inherit from their originating element, not its parent.
                $pseudoStatus = $this->pruner->classify($pseudo['used_styles'], $observation['used_styles']);
                $kept = $this->kept($pseudo['used_styles'], $pseudoStatus);
                // Lifted author intent replaces the frozen pixels in every capture.
                $kept = array_replace($kept, array_intersect_key($lifted[$name] ?? array(), $kept));
                $declarations = array_merge($kept, array('content' => $pseudo['content']));
                // Without a lifted width, a pseudo that fills its originator's content box is auto.
                if (isset($declarations['width']) && !isset($lifted[$name]['width']) && self::fillsOriginator($pseudo['used_styles'], $observation)) $declarations['width'] = 'auto';
                $delta = isset($effective[$name]) ? $this->delta($effective[$name], $declarations, $pseudoStatus, $pseudo['used_styles']) : $declarations;
                $effective[$name] = array_replace($effective[$name] ?? array(), $delta);
                $css .= $this->rule($selector . '::' . $name, $delta);
            } elseif (isset($effective[$name]) && 'none' !== ($effective[$name]['content'] ?? null)) {
                $effective[$name]['content'] = 'none';
                $css .= $this->rule($selector . '::' . $name, array('content' => 'none'));
            }
        }
        return $css;
    }

    /**
     * Whether a pseudo-element's used width is its originating element's
     * content width: a block-level, in-flow box whose margin box exactly
     * fills the originator's content box, in a block container or a
     * stretching column flexbox. That width is the container's decision
     * (`width:auto`), so it follows the copy's container rather than
     * freezing the capture's pixels, which overflow every narrower viewport.
     */
    private static function fillsOriginator(array $pseudo, array $observation): bool
    {
        $box = $observation['border_box']['width'] ?? null;
        $originator = $observation['used_styles'] ?? array();
        if (!is_numeric($box) || !in_array($pseudo['display'] ?? '', array('block', 'flex', 'grid', 'flow-root', 'table', 'list-item'), true)) return false;
        if (!in_array($pseudo['position'] ?? 'static', array('static', 'relative', 'sticky'), true) || 'none' !== ($pseudo['float'] ?? 'none')) return false;
        $display = (string) ($originator['display'] ?? '');
        if (in_array($display, array('flex', 'inline-flex'), true)) {
            $align = 'auto' === ($pseudo['align-self'] ?? 'auto') ? (string) ($originator['align-items'] ?? 'normal') : (string) $pseudo['align-self'];
            if (!str_starts_with(CssValueInspector::declared($originator, 'flex-direction'), 'column') || !in_array($align, array('normal', 'stretch'), true)) return false;
        } elseif (!in_array($display, array('block', 'flow-root', 'list-item', 'inline-block'), true)) {
            return false;
        }
        $edges = static function (array $styles, array $properties): ?float {
            $sum = 0.0;
            foreach ($properties as $property) {
                if (!preg_match('/^(-?(?:\d*\.)?\d+)px\b/', (string) ($styles[$property] ?? '0px'), $match)) return null;
                $sum += (float) $match[1];
            }
            return $sum;
        };
        $originatorEdges = $edges($originator, array('padding-left', 'padding-right', 'border-left', 'border-right'));
        $pseudoEdges = $edges($pseudo, 'border-box' === ($pseudo['box-sizing'] ?? 'content-box') ? array('margin-left', 'margin-right') : array('margin-left', 'margin-right', 'padding-left', 'padding-right', 'border-left', 'border-right'));
        if (null === $originatorEdges || null === $pseudoEdges || !preg_match('/^(?:\d*\.)?\d+px$/', (string) ($pseudo['width'] ?? ''))) return false;
        return abs((float) $pseudo['width'] + $pseudoEdges - ((float) $box - $originatorEdges)) <= 0.5;
    }

    private function rule(string $selector, array $styles): string
    {
        $declarations = array();
        foreach (self::withSideShorthands(self::withUniformBorder($styles)) as $name => $value) {
            // Evidence is external input, not trusted stylesheet syntax.
            if (preg_match('/[{};<>]/', $value) || str_contains($value, '/*')) continue;
            $declarations[] = $name . ':' . $value;
        }
        if (array() === $declarations) return '';
        return $selector . '{' . implode(';', $declarations) . '}';
    }

    private const BORDER_SIDES = array('border-top', 'border-right', 'border-bottom', 'border-left');
    private const BORDER_STYLES = array('none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset');

    /**
     * Evidence records a border per side, so a uniform border would restate
     * the same value four times in every carrier. Four equal sides set exactly
     * the twelve longhands `border-width`, `border-style` and `border-color`
     * set, so the side declarations fold into those three at the first side's
     * place. Not `border`: that shorthand also resets `border-image`, which
     * the evidence never observed. A value that does not read as one width,
     * one style and one color stays per side.
     *
     * @param array<string, string> $styles
     * @return array<string, string>
     */
    private static function withUniformBorder(array $styles): array
    {
        $value = $styles['border-top'] ?? null;
        if (null === $value) return $styles;
        foreach (self::BORDER_SIDES as $side) {
            if (($styles[$side] ?? null) !== $value) return $styles;
        }
        $parts = self::borderParts($value);
        if (null === $parts) return $styles;
        $folded = array();
        foreach ($styles as $name => $declared) {
            if ('border-top' === $name) {
                $folded['border-width'] = $parts[0];
                $folded['border-style'] = $parts[1];
                $folded['border-color'] = $parts[2];
            } elseif (!in_array($name, self::BORDER_SIDES, true)) {
                $folded[$name] = $declared;
            }
        }
        return $folded;
    }

    /**
     * Longhand families evidence records per side or axis, and the shorthand
     * that sets exactly that family: shorthand => [longhands in shorthand
     * order, value form, pattern of properties whose order relative to the
     * family matters].
     */
    private const SIDE_SHORTHANDS = array(
        'margin' => array(array('margin-top', 'margin-right', 'margin-bottom', 'margin-left'), 'box', '/^margin/'),
        'padding' => array(array('padding-top', 'padding-right', 'padding-bottom', 'padding-left'), 'box', '/^padding/'),
        'inset' => array(array('top', 'right', 'bottom', 'left'), 'box', '/^(?:inset|top|right|bottom|left)(?:-|$)/'),
        'gap' => array(array('row-gap', 'column-gap'), 'pair', '/^(?:grid-)?(?:row-|column-)?gap$/'),
        'overflow' => array(array('overflow-x', 'overflow-y'), 'pair', '/^overflow(?:-|$)/'),
        'place-self' => array(array('align-self', 'justify-self'), 'pair', '/^(?:place|align|justify)-self$/'),
        'grid-row' => array(array('grid-row-start', 'grid-row-end'), 'slash', '/^grid(?:-row|-area)?(?:-start|-end)?$/'),
        'grid-column' => array(array('grid-column-start', 'grid-column-end'), 'slash', '/^grid(?:-column|-area)?(?:-start|-end)?$/'),
    );

    /**
     * Evidence records every side and axis as its own longhand, so a carrier
     * restates `margin-top`, `margin-right`, ... where one shorthand sets the
     * same longhands. A family present in full folds into its shorthand at
     * the first longhand's place; the declarations it sets are exactly the
     * folded ones, so the cascade is unchanged. A family stays as longhands
     * when it is partial, when the shorthand is already declared, when a
     * property it overlaps sits between its longhands (its relative order
     * would change), or when a value could not be a shorthand component: a
     * CSS-wide keyword, a substitution that may expand to several tokens, a
     * multi-token value, or an `!important`.
     *
     * @param array<string, string> $styles
     * @return array<string, string>
     */
    private static function withSideShorthands(array $styles): array
    {
        foreach (self::SIDE_SHORTHANDS as $shorthand => [$longhands, $form, $overlap]) {
            if (isset($styles[$shorthand])) continue;
            $values = array();
            foreach ($longhands as $longhand) {
                $value = $styles[$longhand] ?? null;
                if (null === $value || !self::isShorthandComponent($value, 'slash' !== $form)) continue 2;
                $values[] = trim($value);
            }
            $names = array_keys($styles);
            $positions = array_map(static fn(string $longhand): int => (int) array_search($longhand, $names, true), $longhands);
            $first = min($positions);
            foreach (array_slice($names, $first, max($positions) - $first + 1) as $name) {
                if (!in_array($name, $longhands, true) && 1 === preg_match($overlap, $name)) continue 2;
            }
            $folded = array();
            foreach ($styles as $name => $declared) {
                if ($names[$first] === $name) $folded[$shorthand] = self::shorthandValue($values, $form);
                elseif (!in_array($name, $longhands, true)) $folded[$name] = $declared;
            }
            $styles = $folded;
        }
        return $styles;
    }

    /** Whether a longhand value can stand as one component of its shorthand. */
    private static function isShorthandComponent(string $value, bool $singleToken): bool
    {
        $value = trim($value);
        if ('' === $value || str_contains($value, '!') || str_contains($value, '/')) return false;
        if (in_array(strtolower($value), array('inherit', 'initial', 'unset', 'revert', 'revert-layer'), true)) return false;
        if (1 === preg_match('/\b(?:var|env|attr)\(/i', $value)) return false;
        // Outside parentheses a component is one token (a grid line like
        // `span 2` is the exception the slash form keeps apart).
        $depth = 0;
        foreach (str_split($value) as $char) {
            if ('(' === $char) ++$depth;
            elseif (')' === $char) --$depth;
            elseif (0 === $depth && ctype_space($char) && $singleToken) return false;
        }
        return 0 === $depth;
    }

    /** @param list<string> $values Longhand values in shorthand order. */
    private static function shorthandValue(array $values, string $form): string
    {
        if ('slash' === $form) return $values[0] . ' / ' . $values[1];
        if ('pair' === $form) return $values[0] === $values[1] ? $values[0] : $values[0] . ' ' . $values[1];
        [$top, $right, $bottom, $left] = $values;
        if ($right !== $left) return implode(' ', $values);
        if ($top !== $bottom) return $top . ' ' . $right . ' ' . $bottom;
        return $top === $right ? $top : $top . ' ' . $right;
    }

    /** @return array{0:string,1:string,2:string}|null Width, style and color of a side border value. */
    private static function borderParts(string $value): ?array
    {
        $tokens = array();
        $depth = 0;
        $token = '';
        foreach (str_split(trim($value)) as $char) {
            if ('(' === $char) ++$depth;
            elseif (')' === $char) --$depth;
            if (0 === $depth && ctype_space($char)) {
                if ('' !== $token) $tokens[] = $token;
                $token = '';
                continue;
            }
            $token .= $char;
        }
        if ('' !== $token) $tokens[] = $token;
        if (3 !== count($tokens) || 0 !== $depth) return null;
        $width = $style = $color = null;
        foreach ($tokens as $token) {
            $lower = strtolower($token);
            if (null === $width && 1 === preg_match('/^(?:0|\d*\.?\d+(?:px|em|rem|pt|pc|cm|mm|in|q|vw|vh|vmin|vmax|ch|ex)|thin|medium|thick)$/', $lower)) $width = $token;
            elseif (null === $style && in_array($lower, self::BORDER_STYLES, true)) $style = $token;
            elseif (null === $color && (1 !== preg_match('/^(?:var|env|attr)\(/', $lower) || (null !== $width && null !== $style))) $color = $token;
            else return null;
        }
        // A substitution function could stand for any component: it is the
        // color only once the width and style are both explicit before it.
        if (null === $width || null === $style || null === $color) return null;
        return array($width, $style, $color);
    }
}

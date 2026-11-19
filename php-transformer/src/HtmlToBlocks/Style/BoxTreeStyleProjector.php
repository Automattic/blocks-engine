<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/** Restyle an exact source-node carrier before provenance is discarded. */
final class BoxTreeStyleProjector
{
    private const SELECTOR = "\0carrier\0";

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
     *        The painting `<img>` inside an authored core/image `<figure>`: its observations, its parent's, and its lifted author values.
     */
    public function project(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents = array(), string $blockName = '', array $fluid = array(), array $pseudoFluid = array(), string $sourceTag = '', array $image = array()): array
    {
        if ('core/image' === $blockName && 'img' === strtolower($sourceTag)) {
            return array() === $observations ? $attrs : $this->projectImage($attrs, $observations, $viewports, $state, $parents, $fluid, true);
        }
        if (array() !== $observations) $attrs = $this->projectBox($attrs, $observations, $viewports, $state, $parents, $blockName, $fluid, $pseudoFluid);
        $imageObservations = $image['observations'] ?? array();
        if ('core/image' !== $blockName || array() === $imageObservations) return $attrs;
        return $this->projectImage($attrs, $imageObservations, $viewports, $state, $image['parents'] ?? array(), $image['fluid'] ?? array(), false);
    }

    /** One carrier for the block's own box, from its source element's observations. */
    private function projectBox(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents, string $blockName, array $fluid, array $pseudoFluid = array()): array
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
        $bands = $this->liftFluid($declarations, $keys, $fluid);
        $baseKey = $keys[0];
        $base = $declarations[$baseKey];
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
        $consumed = SourceDeclarations::parse($mapper->serialize($mapped['style'])['style']);
        // Border shorthands can serialize as longhands. Retain their captured
        // declarations unless the mapper round-trip represents the same key.
        $css = $this->carrierCss(array_diff_key($base, $consumed), $keys, $declarations, $status, $observations, $viewports, $bands, true, $parents, self::syntheticAnchorCarrier($blockName, $attrs), $pseudoFluid, self::SELECTOR, 'core/button' === $blockName);
        $class = $this->carrier($css, $state);
        if ('' === $class) return $attrs;
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
    }

    private static function runtime(): Runtime
    {
        static $runtime = null;
        return $runtime ??= new Runtime();
    }

    /**
     * Every declaration observed on the source `<img>`, banded per capture as
     * for any carrier, styles the `<img>` core/image paints, never its
     * `<figure>`: a carrier on the figure would leave the painting image to
     * its intrinsic size and core's `height:auto;max-width:100%`. When the
     * source had no figure, core/image's synthetic one generates no box
     * (`display:contents`), so the source box structure is reproduced. An
     * authored figure keeps its own carrier (margins, position, size) from
     * its own observations. Native image dimensions serialize inline on the
     * `<img>` and would beat each capture's band, so the carrier replaces them.
     *
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     * @param bool $synthetic Whether the figure is core/image's own rather than the source's.
     */
    private function projectImage(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents, array $fluid, bool $synthetic): array
    {
        $keys = array_keys($observations);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $status[$key] = $this->pruner->classify($observations[$key]['used_styles'], is_array($parent) ? $parent : null, 'core/image', 'img');
            $declarations[$key] = $this->kept($observations[$key]['used_styles'], $status[$key]);
        }
        $bands = $this->liftFluid($declarations, $keys, $fluid);
        if ($synthetic) {
            // The figure's style attributes are the image's: never resurrect an observed one.
            $mapper = new StyleAttributeMapper();
            $existing = SourceDeclarations::parse($mapper->serialize(is_array($attrs['style'] ?? null) ? $attrs['style'] : array())['style']);
            $mapped = $mapper->map(array_diff_key($existing, $observations[$keys[0]]['used_styles']));
            $attrs['style'] = $mapped['style'];
            $attrs = array_replace($attrs, $mapped['attrs']);
        }
        foreach (self::IMAGE_DIMENSION_ATTRIBUTES as $attribute => $property) {
            if (isset($observations[$keys[0]]['used_styles'][$property])) unset($attrs[$attribute]);
        }
        // `is-resized` marks native dimensions the carrier now owns.
        if (!isset($attrs['width']) && !isset($attrs['height'])) {
            $attrs['className'] = implode(' ', array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), static fn(string $class): bool => '' !== $class && 'is-resized' !== $class));
            if ('' === $attrs['className']) unset($attrs['className']);
        }
        $css = $this->carrierCss($declarations[$keys[0]], $keys, $declarations, $status, $observations, $viewports, $bands, false, $parents, false, array(), self::IMAGE_SELECTOR);
        $class = $this->carrier(($synthetic ? $this->rule(self::SELECTOR, array('display' => 'contents')) : '') . $css, $state);
        if ('' === $class) return $attrs;
        if ($synthetic) $state->markBoxlessImageFigureCarrier($class);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
    }

    /** The painting image inside a core/image figure, linked or not. */
    private const IMAGE_SELECTOR = self::SELECTOR . ' img';

    /** core/image attributes serialized inline on its `<img>` => the observed property each sets. */
    private const IMAGE_DIMENSION_ATTRIBUTES = array('width' => 'width', 'height' => 'height', 'aspectRatio' => 'aspect-ratio', 'scale' => 'object-fit');

    /**
     * Restyle an inline element inside RichText, which has no block
     * attributes: its observed typography that differs from its source
     * parent's goes entirely to a carrier class. A value equal to the
     * parent's is left to inheritance, so a lifted fluid block value keeps
     * reaching it between captures. An inline the author made a box of its
     * own (a `flex` badge, an `inline-block` pill) carries its observed box
     * too: nothing else restates it once the author stylesheet is not
     * shipped. A flex or grid item's blockified `block` is not such a box.
     *
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     * @return string The carrier class, or '' when the inline inherits everything observed.
     */
    public function inlineCarrier(array $observations, array $viewports, LayoutGeometryState $state, array $parents, array $fluid = array()): string
    {
        $boxed = self::ownsInlineBox($observations, $viewports, $parents);
        $properties = array_flip(self::INLINE_TYPOGRAPHY);
        $typography = array();
        foreach ($observations as $key => $observation) {
            if (!isset($viewports[$key]['width']) || !is_array($observation['used_styles'] ?? null)) continue;
            $typography[$key] = array('used_styles' => $boxed ? array_diff_key($observation['used_styles'], self::INLINE_STATE_OWNED) : array_intersect_key($observation['used_styles'], $properties));
        }
        if (array() === $typography) return '';
        $keys = array_keys($typography);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $status[$key] = $this->pruner->classify($typography[$key]['used_styles'], is_array($parent) ? $parent : null);
            $declarations[$key] = $this->kept($typography[$key]['used_styles'], $status[$key]);
        }
        $bands = $this->liftFluid($declarations, $keys, $boxed ? $fluid : array_intersect_key($fluid, $properties));
        return $this->carrier($this->carrierCss($declarations[$keys[0]], $keys, $declarations, $status, $typography, $viewports, $bands, false, $parents), $state);
    }

    /** Whether a RichText inline generates its own box at some capture, beyond a blockified flex or grid item. */
    private static function ownsInlineBox(array $observations, array $viewports, array $parents): bool
    {
        foreach ($observations as $key => $observation) {
            if (!isset($viewports[$key]['width'])) continue;
            $display = strtolower(trim((string) ($observation['used_styles']['display'] ?? '')));
            if (in_array($display, array('', 'inline', 'contents', 'none'), true)) continue;
            $parentDisplay = strtolower(trim((string) ($parents[$key]['used_styles']['display'] ?? '')));
            if ('block' === $display && 1 === preg_match('/(?:^|[\s-])(?:flex|grid)$/', $parentDisplay)) continue;
            return true;
        }
        return false;
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
        $bands = $this->liftFluid($declarations, $keys, $fluid);
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

    /**
     * Inherited typography an inline can set apart from its block. Color
     * stays with the link and state styles that already own it.
     */
    /** Keyword properties BoxTreeFluidIntent lifts only when they explain every capture, pruned or not. */
    private const SWITCHED_KEYWORDS = array('display' => true, 'visibility' => true);

    /** Observed anchor properties that lay out a core/button link's content. */
    private const BUTTON_LINK_LAYOUT = array('display' => true, 'flex-direction' => true, 'flex-wrap' => true, 'row-gap' => true, 'column-gap' => true, 'justify-content' => true, 'align-items' => true, 'align-content' => true, 'text-align' => true);

    /** Inherited paint the link and state styles already own, never restated on an inline. */
    private const INLINE_STATE_OWNED = array('color' => true, 'text-decoration-color' => true);

    /**
     * Grid lines BoxTreeFluidIntent lifts only from author winners at every
     * capture: a capture pruned at the initial `auto` is still explained, so
     * a placement switching to `auto` follows the author's breakpoint. Lines
     * every capture pruned (not a grid item) stay out.
     */
    private const GRID_LINES = array('grid-row-start' => true, 'grid-row-end' => true, 'grid-column-start' => true, 'grid-column-end' => true);

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
     * @param array<string, array<string, string>> $declarations Kept declarations by viewport key, updated in place.
     * @param list<string> $keys Viewport keys, widest first.
     * @return array<string, array<string, string>> Band declarations by max-width.
     */
    private function liftFluid(array &$declarations, array $keys, array $fluid): array
    {
        $bands = array();
        foreach ($fluid as $property => $propertyBands) {
            if (array() === $propertyBands || null !== $propertyBands[0][0]) continue;
            $kept = 0;
            foreach ($keys as $key) {
                if (isset($declarations[$key][$property])) {
                    ++$kept;
                    continue;
                }
                if (!isset(self::SWITCHED_KEYWORDS[$property]) && !isset(self::GRID_LINES[$property])) continue 2;
            }
            if (0 === $kept && isset(self::GRID_LINES[$property])) continue;
            foreach ($keys as $key) $declarations[$key][$property] = $propertyBands[0][1];
            foreach (array_slice($propertyBands, 1) as [$maxWidth, $value]) {
                $bands[self::width((float) $maxWidth)][$property] = $value;
            }
        }
        return $bands;
    }

    /**
     * The base rule, then each narrower capture's delta, then the lifted bands.
     *
     * @param array<string, array<string, mixed>> $parents The block parent's observations, by viewport key.
     * @param array<string, array<string, list<array{0: float|null, 1: string}>>> $pseudoFluid Lifted pseudo-element values, by name.
     */
    private function carrierCss(array $base, array $keys, array $declarations, array $status, array $observations, array $viewports, array $bands, bool $pseudos, array $parents = array(), bool $syntheticAnchor = false, array $pseudoFluid = array(), string $selector = self::SELECTOR, bool $buttonLink = false): string
    {
        $baseKey = $keys[0];
        $rule = function (array $declared) use ($syntheticAnchor, $selector, $buttonLink): string {
            $css = $this->rule($selector, $declared);
            if ($buttonLink) {
                // core/button paints the source anchor as its inner link, which
                // holds the anchor's content: the anchor's own layout of that
                // content (a flex row with a gap, centered text) lays it out.
                $css .= $this->rule($selector . '>.wp-block-button__link', array_intersect_key($declared, self::BUTTON_LINK_LAYOUT));
            }
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
        if ($pseudos) $css .= $this->pseudoRules($observations[$baseKey], $pseudoEffective, $pseudoLifted);
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
            $pseudo = $pseudos ? $this->pseudoRules($observations[$key], $pseudoEffective, $pseudoLifted) : '';
            if (array() === $delta && '' === $pseudo) continue;
            $css .= '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $rule($delta) . $pseudo . '}';
        }
        // Numeric keys become ints: compare them as widths, narrowest last.
        $widths = array_keys($bands + $pseudoBands);
        usort($widths, static fn(int|string $a, int|string $b): int => (float) $b <=> (float) $a);
        foreach ($widths as $maxWidth) {
            $rules = isset($bands[$maxWidth]) ? $rule($bands[$maxWidth]) : '';
            foreach ($pseudoBands[$maxWidth] ?? array() as $name => $declared) $rules .= $this->rule(self::SELECTOR . '::' . $name, $declared);
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
        $state->registerRule($class, str_replace(self::SELECTOR, '.' . $class . '.' . $class, $css));
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
    private function pseudoRules(array $observation, array &$effective, array $lifted = array()): string
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
                $css .= $this->rule(self::SELECTOR . '::' . $name, $delta);
            } elseif (isset($effective[$name]) && 'none' !== ($effective[$name]['content'] ?? null)) {
                $effective[$name]['content'] = 'none';
                $css .= $this->rule(self::SELECTOR . '::' . $name, array('content' => 'none'));
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
            if (!str_starts_with((string) ($originator['flex-direction'] ?? 'row'), 'column') || !in_array($align, array('normal', 'stretch'), true)) return false;
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

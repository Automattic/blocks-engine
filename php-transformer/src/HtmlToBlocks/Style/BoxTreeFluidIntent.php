<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFontShorthand;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use DOMElement;

/**
 * Lift author intent proven at every captured viewport onto a carrier
 * (ADR 0005 §4), so widths between captures follow the author rather than
 * the nearest frozen capture.
 *
 * - A resting cascade winner that is a fluid expression (viewport units,
 *   `%`, `em`, CSS math, `var()`), reached directly or through the `font`,
 *   `margin` or `padding` shorthand, is lifted when it evaluates to the
 *   observed used value (±0.5px) at every capture. Every `var()` is expanded
 *   at the element's scope and width, so the carrier references no custom
 *   property.
 * - On a heading, an inherited property with no author winner whose observed
 *   value equals the parent's at every capture, and varies between captures,
 *   is lifted as `inherit`. That overrides the heading's user-agent default
 *   and follows the parent at every width. It is limited to headings: their
 *   source parent stays their block parent, while blocks core rebuilds
 *   (navigation items, buttons) sit in wrappers the source never had.
 * - A `width` or horizontal margin the author leaves `auto` (or declares
 *   `auto`) is lifted as `auto` when the box fills its line at every capture:
 *   a block-level box in a block container, a stretched column-flex item, a
 *   grid item in a uniform track, or an absolutely positioned box with both
 *   horizontal insets, whose margin box equals the space its parent gives
 *   it. Frozen pixels there pin the box to one capture's width and overflow
 *   every narrower viewport.
 * - A `grid-template-columns` track list in `fr` (with `px`, `repeat()` and
 *   `minmax(<px>, <fr>)`) is lifted when it resolves to the observed tracks
 *   at every capture; an undeclared list where the one implicit column fills
 *   the grid. Its bands between captures resolve by that same arithmetic.
 *
 * Where the author's cascade switches between captures (a width query that
 * redefines a scaling token), the lift is a set of bands keyed by the
 * author's own breakpoint, not the capture width. Each band must contain a
 * capture that proves its value (track lists excepted, above); otherwise the
 * property keeps its pixels.
 */
final class BoxTreeFluidIntent
{
    private const TOLERANCE = 0.5;

    /** Longhand => the shorthand that also sets it. */
    private const SHORTHANDS = array(
        'font-size' => 'font', 'line-height' => 'font', 'font-family' => 'font',
        'font-weight' => 'font', 'font-style' => 'font',
        'margin-top' => 'margin', 'margin-right' => 'margin', 'margin-bottom' => 'margin', 'margin-left' => 'margin',
        'padding-top' => 'padding', 'padding-right' => 'padding', 'padding-bottom' => 'padding', 'padding-left' => 'padding',
    );

    private const SIDES = array('top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3);

    /** Blocks whose carrier may restate an inherited value as `inherit`. */
    private const INHERITING_BLOCKS = array('core/heading');

    private DOMElement $element;
    private StyleResolver $resolver;
    /** @var list<string> */
    private array $requested = array();
    /** @var array<string, array<string, string>> Cascade winners by width. */
    private array $winners = array();

    /**
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @param array<string, array<string, mixed>> $parents Captured observations of the element's parent, by viewport key.
     * @param string $blockName The block the element becomes.
     * @return array<string, list<array{0: float|null, 1: string}>> Property => bands, widest first:
     *         [max-width or null for the base rule, value].
     */
    public function lift(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, array $parents, string $blockName = ''): array
    {
        if (array() === $observations) return array();
        $widths = array();
        foreach ($observations as $key => $_) {
            if (!isset($viewports[$key]['width'])) return array();
            $widths[$key] = (float) $viewports[$key]['width'];
        }
        arsort($widths);
        $this->element = $element;
        $this->resolver = $resolver;
        $this->winners = array();
        $properties = array_keys(reset($observations)['used_styles'] ?? array());
        $this->requested = array_values(array_unique(array_merge($properties, array_values(array_intersect_key(self::SHORTHANDS, array_flip($properties))))));
        // The widths where the author's cascade can change between captures.
        $samples = array();
        $widest = reset($widths);
        $narrowest = end($widths);
        foreach ($resolver->authorViewport->widthBreakpoints() as $breakpoint) {
            // A `min-width` query switches below its breakpoint, a `max-width`
            // query above it: sample both sides.
            foreach (array(floor($breakpoint) - 1, floor($breakpoint), floor($breakpoint) + 1) as $width) {
                if ($width > $narrowest && $width < $widest) $samples[(string) $width] = $width;
            }
        }
        foreach ($widths as $width) unset($samples[(string) $width]);
        $inheriting = in_array($blockName, self::INHERITING_BLOCKS, true);
        $lifted = array();
        foreach ($properties as $property) {
            $bands = $this->liftedExpression($property, $widths, $samples, $observations, $viewports, $parents)
                ?? $this->liftedTracks($property, $widths, $samples, $observations)
                ?? $this->liftedFill($property, $widths, $samples, $observations, $parents)
                ?? ($inheriting ? $this->liftedInheritance($property, $widths, $samples, $observations, $parents) : null);
            if (null !== $bands) $lifted[$property] = $bands;
        }
        return $lifted;
    }

    /**
     * @param array<string, float> $widths Capture widths by key, widest first.
     * @param array<string, float> $samples Widths between captures where the cascade can switch.
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedExpression(string $property, array $widths, array $samples, array $observations, array $viewports, array $parents): ?array
    {
        $proven = array();
        foreach ($widths as $key => $width) {
            $value = $this->declared($property, $width);
            if (null === $value || '' === $value) return null;
            if (!preg_match('/%|\d(?:vw|vh|vmin|vmax|em)\b|\b(?:calc|clamp|min|max)\(/i', $value)) return null;
            $styles = $observations[$key]['used_styles'] ?? array();
            $parent = $parents[$key]['used_styles'] ?? array();
            [$percent, $em] = $this->bases($property, $styles, $parent);
            $actual = self::pixels((string) ($styles[$property] ?? ''));
            $predicted = CssFluidLength::evaluate($value, $width, (float) ($viewports[$key]['height'] ?? 0), $percent, $em);
            // The box's own min/max-width (kept on the carrier) clamp its width.
            if ('width' === $property && null !== $predicted) {
                $max = self::pixels((string) ($styles['max-width'] ?? ''));
                $min = self::pixels((string) ($styles['min-width'] ?? ''));
                if (null !== $max) $predicted = min($predicted, $max);
                if (null !== $min) $predicted = max($predicted, $min);
            }
            if (null === $actual || null === $predicted || abs($actual - $predicted) > self::TOLERANCE) return null;
            $proven[$key] = $value;
        }
        return $this->bands($property, $widths, $samples, $proven);
    }

    private const INTRINSIC_ELEMENTS = array('img', 'picture', 'video', 'audio', 'iframe', 'canvas', 'svg', 'object', 'embed', 'input', 'textarea', 'select', 'button', 'progress', 'meter', 'table');

    /**
     * `auto` width and horizontal margins of a box that fills its line: the
     * author's own value, proven at every capture where the box renders. An
     * `auto` width is the space its parent gives it less its margins, clamped
     * by its own min/max-width; `auto` margins take what the box leaves.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedFill(string $property, array $widths, array $samples, array $observations, array $parents): ?array
    {
        if (!in_array($property, array('width', 'margin-left', 'margin-right'), true)) return null;
        // Replaced elements and form controls size to their content, not their line.
        if (in_array(strtolower($this->element->tagName), self::INTRINSIC_ELEMENTS, true)) return null;
        $other = array('margin-left' => 'margin-right', 'margin-right' => 'margin-left')[$property] ?? null;
        $bothAuto = null !== $other;
        foreach ($widths + $samples as $width) {
            $declared = $this->declared($property, $width);
            // An undeclared width is `auto`; an undeclared margin is `0`.
            if ('width' === $property ? null !== $declared && 'auto' !== strtolower($declared) : 'auto' !== strtolower((string) $declared)) return null;
            if (null !== $other && 'auto' !== strtolower((string) $this->declared($other, $width))) $bothAuto = false;
        }
        $proven = false;
        foreach ($widths as $key => $_) {
            $styles = $observations[$key]['used_styles'] ?? array();
            if ('none' === ($styles['display'] ?? '')) continue;
            $available = self::fillSpace($styles, $parents[$key]['used_styles'] ?? array());
            $box = self::borderBoxWidth($styles, 'width');
            $left = self::pixels((string) ($styles['margin-left'] ?? ''));
            $right = self::pixels((string) ($styles['margin-right'] ?? ''));
            if (null === $available || null === $box || null === $left || null === $right) return null;
            if ('width' === $property) {
                $predicted = $available - $left - $right;
                $max = self::borderBoxWidth($styles, 'max-width');
                $min = self::borderBoxWidth($styles, 'min-width');
                if (null !== $max) $predicted = min($predicted, $max);
                if (null !== $min) $predicted = max($predicted, $min);
                if (abs($predicted - $box) > self::TOLERANCE) return null;
            } else {
                if (abs($box + $left + $right - $available) > self::TOLERANCE) return null;
                if ($bothAuto && abs($left - $right) > 1.0) return null;
            }
            $proven = true;
        }
        return $proven ? array(array(null, 'auto')) : null;
    }

    /**
     * The width an `auto`-width box's margin box fills, from observed facts:
     * the parent's content width for an in-flow block-level box in a block
     * container or a stretched column-flex item, the track for a grid item
     * auto-placed in uniform tracks, the parent's padding box less the insets
     * for an absolutely positioned box with both insets set inside a
     * positioned parent. null when `auto` would not fill.
     */
    private static function fillSpace(array $styles, array $parent): ?float
    {
        if (!in_array($styles['display'] ?? '', array('block', 'flex', 'grid', 'flow-root', 'list-item'), true)) return null;
        $position = $styles['position'] ?? 'static';
        if ('absolute' === $position) {
            if ('static' === ($parent['position'] ?? 'static')) return null;
            $left = self::pixels((string) ($styles['left'] ?? ''));
            $right = self::pixels((string) ($styles['right'] ?? ''));
            $content = self::contentWidth($parent);
            $padding = self::horizontalEdges($parent, false);
            return null === $left || null === $right || null === $content || null === $padding ? null : $content + $padding - $left - $right;
        }
        if (!in_array($position, array('static', 'relative', 'sticky'), true) || 'none' !== ($styles['float'] ?? 'none')) return null;
        $display = $parent['display'] ?? '';
        if (in_array($display, array('block', 'flow-root', 'list-item'), true)) return self::contentWidth($parent);
        if (in_array($display, array('flex', 'inline-flex'), true)) {
            if (!in_array($parent['flex-direction'] ?? '', array('column', 'column-reverse'), true) || 'nowrap' !== ($parent['flex-wrap'] ?? '')) return null;
            $align = $styles['align-self'] ?? 'auto';
            if ('auto' === $align) $align = $parent['align-items'] ?? '';
            return in_array($align, array('normal', 'stretch'), true) ? self::contentWidth($parent) : null;
        }
        if (in_array($display, array('grid', 'inline-grid'), true)) {
            foreach (array('grid-column-start', 'grid-column-end') as $line) {
                if (!in_array($styles[$line] ?? '', array('auto', 'span 1'), true)) return null;
            }
            $justify = $styles['justify-self'] ?? 'auto';
            if ('auto' === $justify) $justify = $parent['justify-items'] ?? '';
            if (!in_array($justify, array('normal', 'stretch', 'legacy'), true)) return null;
            $tracks = array_map(static fn(string $track): ?float => self::pixels($track), preg_split('/\s+/', trim((string) ($parent['grid-template-columns'] ?? ''))) ?: array());
            if (array() === $tracks || in_array(null, $tracks, true) || max($tracks) - min($tracks) > self::TOLERANCE) return null;
            return $tracks[0];
        }
        return null;
    }

    /** A box's content width from its observed `width`, padding and borders. */
    private static function contentWidth(array $styles): ?float
    {
        $width = self::pixels((string) ($styles['width'] ?? ''));
        if (null === $width || 'border-box' !== ($styles['box-sizing'] ?? '')) return $width;
        $edges = self::horizontalEdges($styles);
        return null === $edges ? null : $width - $edges;
    }

    /** A box's observed `width`, `min-width` or `max-width` as a border-box length; null when not in pixels. */
    private static function borderBoxWidth(array $styles, string $property): ?float
    {
        $width = self::pixels((string) ($styles[$property] ?? ''));
        if (null === $width || 'border-box' === ($styles['box-sizing'] ?? '')) return $width;
        $edges = self::horizontalEdges($styles);
        return null === $edges ? null : $width + $edges;
    }

    /** Horizontal padding, and with $borders the border widths, of a box. */
    private static function horizontalEdges(array $styles, bool $borders = true): ?float
    {
        $sum = 0.0;
        foreach (array('padding-left', 'padding-right') as $property) {
            $value = self::pixels((string) ($styles[$property] ?? ''));
            if (null === $value) return null;
            $sum += $value;
        }
        if (!$borders) return $sum;
        foreach (array('border-left', 'border-right') as $property) {
            $border = trim((string) ($styles[$property] ?? '0px'));
            if (preg_match('/(?:^|\s)(?:none|hidden)(?:\s|$)/', $border)) continue;
            if (!preg_match('/^(-?(?:\d*\.)?\d+px)\b/', $border, $m)) return null;
            $sum += (float) CssLength::toPx($m[1]);
        }
        return $sum;
    }

    /**
     * A `grid-template-columns` track list in `fr` that resolves to the
     * observed tracks at every capture. An undeclared list (`none`) holds
     * where the one implicit column fills the content width. A band between
     * captures carries a list resolved by the same arithmetic the captures
     * proved, so it needs no capture of its own.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedTracks(string $property, array $widths, array $samples, array $observations): ?array
    {
        if ('grid-template-columns' !== $property) return null;
        $proven = array();
        $fractional = false;
        foreach ($widths as $key => $width) {
            $value = $this->declared($property, $width) ?? 'none';
            $styles = $observations[$key]['used_styles'] ?? array();
            if (!in_array($styles['display'] ?? '', array('grid', 'inline-grid'), true)) return null;
            $content = self::contentWidth($styles);
            $gap = 'normal' === ($styles['column-gap'] ?? 'normal') ? 0.0 : self::pixels((string) $styles['column-gap']);
            $observed = array_map(static fn(string $track): ?float => self::pixels($track), preg_split('/\s+/', trim((string) ($styles[$property] ?? ''))) ?: array());
            if (null === $content || null === $gap || in_array(null, $observed, true)) return null;
            if ('none' === strtolower($value)) {
                $predicted = array($content);
            } else {
                if (!preg_match('/\dfr\b/i', $value)) return null;
                $predicted = self::resolveTracks($value, $content, $gap);
                $fractional = true;
            }
            if (null === $predicted || count($predicted) !== count($observed)) return null;
            foreach ($predicted as $index => $track) {
                if (abs($track - $observed[$index]) > self::TOLERANCE) return null;
            }
            $proven[$key] = $value;
        }
        if (!$fractional) return null;
        foreach ($samples as $width) {
            $value = $this->declared($property, $width) ?? 'none';
            if ('none' !== strtolower($value) && null === self::resolveTracks($value, 1000.0, 0.0)) return null;
        }
        return $this->bands($property, $widths, $samples, $proven, 'none', true);
    }

    /**
     * Track sizes a list of `px`, `fr` and `minmax(<px>, <fr>)` tracks (with
     * integer `repeat()`) resolves to in a content width. null for anything
     * else (keywords, named lines, auto-repeat), whose size depends on content.
     *
     * @return list<float>|null
     */
    private static function resolveTracks(string $value, float $content, float $gap): ?array
    {
        $tracks = array();
        $value = trim($value);
        while ('' !== $value) {
            if (preg_match('/^repeat\(\s*(\d+)\s*,/i', $value, $m)) {
                $depth = 0;
                $end = null;
                for ($i = strlen('repeat'); $i < strlen($value); $i++) {
                    if ('(' === $value[$i]) $depth++;
                    elseif (')' === $value[$i] && 0 === --$depth) { $end = $i; break; }
                }
                if (null === $end || (int) $m[1] < 1 || (int) $m[1] > 1000) return null;
                $inner = self::resolveTrackTokens(substr($value, strlen($m[0]), $end - strlen($m[0])));
                if (null === $inner) return null;
                for ($i = 0; $i < (int) $m[1]; $i++) array_push($tracks, ...$inner);
                $value = ltrim(substr($value, $end + 1));
                continue;
            }
            if (!preg_match('/^(minmax\([^()]*\)|[^\s()]+)\s*/i', $value, $m)) return null;
            $inner = self::resolveTrackTokens($m[1]);
            if (null === $inner) return null;
            array_push($tracks, ...$inner);
            $value = substr($value, strlen($m[0]));
        }
        if (array() === $tracks) return null;
        $free = $content - $gap * (count($tracks) - 1);
        $fractions = 0.0;
        foreach ($tracks as [$min, $fr]) {
            if (null === $fr) $free -= $min;
            else $fractions += $fr;
        }
        $sizes = array();
        foreach ($tracks as [$min, $fr]) {
            if (null === $fr) { $sizes[] = $min; continue; }
            $share = $fractions > 0 ? max(0.0, $free) * $fr / $fractions : 0.0;
            // A track whose minimum exceeds its share redistributes the rest: unproven.
            if ($share < $min) return null;
            $sizes[] = $share;
        }
        return $sizes;
    }

    /** @return list<array{0: float, 1: float|null}>|null [minimum px, fr or null for a fixed track] per track. */
    private static function resolveTrackTokens(string $tokens): ?array
    {
        $tracks = array();
        foreach (preg_split('/\s+(?![^()]*\))/', trim($tokens)) ?: array() as $token) {
            if ('' === $token) continue;
            if (preg_match('/^(\d*\.?\d+)fr$/i', $token, $m)) {
                // A bare `fr` track's automatic minimum is its content: the
                // observed tracks prove it does not bind at the captures.
                $tracks[] = array(0.0, (float) $m[1]);
                continue;
            }
            if (preg_match('/^minmax\(\s*(0|(?:\d*\.)?\d+px)\s*,\s*(\d*\.?\d+)fr\s*\)$/i', $token, $m)) {
                $tracks[] = array('0' === $m[1] ? 0.0 : (float) CssLength::toPx($m[1]), (float) $m[2]);
                continue;
            }
            $px = self::pixels($token);
            if (null === $px) return null;
            $tracks[] = array($px, null);
        }
        return $tracks;
    }

    /**
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedInheritance(string $property, array $widths, array $samples, array $observations, array $parents): ?array
    {
        if (!in_array($property, BoxTree::INHERITED_PROPERTIES, true) || count($widths) < 2) return null;
        $values = array();
        foreach ($widths + $samples as $key => $width) {
            $declared = $this->declared($property, $width);
            if (null !== $declared && 'inherit' !== strtolower($declared)) return null;
            if (!isset($observations[$key])) continue;
            $own = $observations[$key]['used_styles'][$property] ?? null;
            $parent = $parents[$key]['used_styles'][$property] ?? null;
            if (!is_string($own) || '' === $own || $own !== $parent) return null;
            $values[$own] = true;
        }
        // An invariant value needs no viewport delta: the pixels are exact.
        return count($values) > 1 ? array(array(null, 'inherit')) : null;
    }

    /**
     * Split the author's value into bands at the breakpoints where it
     * switches between captures. Each band must hold a capture whose proven
     * value it carries, unless $uncovered: the caller proved the value model
     * itself. $initial stands in for an undeclared value.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @param array<string, string> $proven Proven value by capture key.
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function bands(string $property, array $widths, array $samples, array $proven, ?string $initial = null, bool $uncovered = false): ?array
    {
        $points = array();
        foreach ($widths as $key => $width) $points[] = array($width, $proven[$key], true);
        foreach ($samples as $width) {
            $value = $this->declared($property, $width) ?? $initial;
            if (null === $value || '' === $value) return null;
            $points[] = array($width, $value, false);
        }
        usort($points, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $bands = array();
        $covered = array();
        foreach ($points as [$width, $value, $capture]) {
            $last = array_key_last($bands);
            if (null === $last || $bands[$last][1] !== $value) {
                $bands[] = array(null === $last ? null : $width, $value);
                $last = array_key_last($bands);
            }
            if ($capture) $covered[$last] = true;
        }
        return $uncovered || count($covered) === count($bands) ? $bands : null;
    }

    /**
     * The author value that wins for a longhand at one width, read through
     * its shorthand when the shorthand is declared later in the cascade. null
     * when nothing is declared; '' when the declaration cannot be resolved.
     */
    private function declared(string $property, float $width): ?string
    {
        $winners = $this->winners[(string) $width] ??= $this->resolver->authorViewport->atViewport($this->element, $this->requested, $width);
        $order = array_flip(array_keys($winners));
        $shorthand = self::SHORTHANDS[$property] ?? null;
        $fromShorthand = null !== $shorthand && isset($order[$shorthand]) && (!isset($order[$property]) || $order[$shorthand] > $order[$property]);
        $name = $fromShorthand ? $shorthand : $property;
        if (!isset($winners[$name])) return null;
        $value = trim(CssValueInspector::withoutImportant((string) $winners[$name]));
        if ('' === $value) return null;
        $value = trim($this->resolver->customProperties->resolveAtViewport($value, $this->element, $width));
        if ('' === $value || !$fromShorthand) return $value;
        if ('font' === $shorthand) {
            return trim(CssValueInspector::withoutImportant(CssFontShorthand::expand($value, true)[$property] ?? ''));
        }
        $side = self::SIDES[substr($property, strrpos($property, '-') + 1)] ?? null;
        return null === $side ? '' : CssValueInspector::expandBoxShorthand($value)[$side];
    }

    /**
     * What `100%` and `1em` resolve to for a property, from observed facts.
     * Width percentages use the parent's content width; other percentage
     * bases (positioned boxes, heights, margins) need layout facts the
     * evidence does not carry, so they stay unknown.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function bases(string $property, array $styles, array $parent): array
    {
        $own = self::pixels((string) ($styles['font-size'] ?? ''));
        $inherited = self::pixels((string) ($parent['font-size'] ?? ''));
        if ('font-size' === $property) return array($inherited, $inherited);
        if ('line-height' === $property) return array($own, $own);
        $percent = null;
        if (in_array($property, array('width', 'min-width', 'max-width'), true)
            && !in_array($styles['position'] ?? '', array('absolute', 'fixed'), true)
            && !in_array($parent['display'] ?? '', array('inline', 'contents', 'grid', 'inline-grid'), true)) {
            $percent = self::contentWidth($parent);
        }
        return array($percent, $own);
    }

    private static function pixels(string $value): ?float
    {
        return preg_match('/^-?(?:\d*\.)?\d+px$/', $value) ? CssLength::toPx($value) : null;
    }
}

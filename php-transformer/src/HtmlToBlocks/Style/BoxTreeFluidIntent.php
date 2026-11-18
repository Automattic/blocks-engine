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
 * - `rem` lengths in a lifted expression are restated in pixels against
 *   the root font size the author's cascade gives the document at that
 *   width (the user-agent 16px when undeclared), so the carrier does not
 *   depend on the destination's root font size.
 * - A unitless `line-height` scales with the element's font size: it is
 *   lifted when the observed line height differs between captures and
 *   equals that factor times the observed font size at every capture.
 * - On a heading, an inherited property with no author winner whose observed
 *   value equals the parent's at every capture, and varies between captures,
 *   is lifted as `inherit`. That overrides the heading's user-agent default
 *   and follows the parent at every width. It is limited to headings: their
 *   source parent stays their block parent, while blocks core rebuilds
 *   (navigation items, buttons) sit in wrappers the source never had.
 *
 * Where the author's cascade switches between captures (a width query that
 * redefines a scaling token), the lift is a set of bands keyed by the
 * author's own breakpoint, not the capture width. Each band must contain a
 * capture that proves its value; otherwise the property keeps its pixels.
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

    /** Longhands whose percentages resolve against the containing block's width. */
    private const INLINE_PERCENT_PROPERTIES = array(
        'width', 'min-width', 'max-width',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
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
    /** @var array<string, float|null> Root font size by width. */
    private array $rootFontSizes = array();

    /**
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @param array<string, array<string, mixed>> $parents Captured observations of the element's parent, by viewport key.
     * @param string $blockName The block the element becomes.
     * @param array<string, array<string, mixed>>|null $layoutParents Captured observations of the box the element lays out in
     *        (its parent, or the nearest ancestor that is not `display:contents`); defaults to $parents.
     * @return array<string, list<array{0: float|null, 1: string}>> Property => bands, widest first:
     *         [max-width or null for the base rule, value].
     */
    public function lift(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, array $parents, string $blockName = '', ?array $layoutParents = null): array
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
        $this->rootFontSizes = array();
        $properties = array_keys(reset($observations)['used_styles'] ?? array());
        $this->requested = array_values(array_unique(array_merge($properties, array_values(array_intersect_key(self::SHORTHANDS, array_flip($properties))))));
        // The widths where the author's cascade can change between captures.
        $samples = array();
        $widest = reset($widths);
        $narrowest = end($widths);
        foreach ($resolver->authorViewport->widthBreakpoints() as $breakpoint) {
            foreach (array(floor($breakpoint), floor($breakpoint) + 1) as $width) {
                if ($width > $narrowest && $width < $widest) $samples[(string) $width] = $width;
            }
        }
        foreach ($widths as $width) unset($samples[(string) $width]);
        $inheriting = in_array($blockName, self::INHERITING_BLOCKS, true);
        $lifted = array();
        foreach ($properties as $property) {
            $bands = $this->liftedExpression($property, $widths, $samples, $observations, $viewports, $parents, $layoutParents ?? $parents)
                ?? ('width' === $property ? $this->liftedAutoWidth($widths, $samples, $observations, $viewports, $layoutParents ?? $parents) : null)
                ?? ('grid-template-columns' === $property ? $this->liftedGridTracks($widths, $samples, $observations) : null)
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
    private function liftedExpression(string $property, array $widths, array $samples, array $observations, array $viewports, array $parents, array $layoutParents): ?array
    {
        $proven = array();
        $observed = array();
        foreach ($widths as $key => $width) {
            $value = $this->declared($property, $width);
            if (null === $value || '' === $value) return null;
            $factor = 'line-height' === $property && preg_match('/^\d*\.?\d+$/', $value);
            if (!$factor && !preg_match('/%|\d(?:vw|vh|vmin|vmax|em)\b|\b(?:calc|clamp|min|max)\(/i', $value)) return null;
            $styles = $observations[$key]['used_styles'] ?? array();
            $parent = $parents[$key]['used_styles'] ?? array();
            // Sizing percentages resolve against the box laid out in, past `display:contents`.
            $sizing = in_array($property, self::INLINE_PERCENT_PROPERTIES, true);
            $base = $sizing ? ($layoutParents[$key] ?? array()) : ($parents[$key] ?? array());
            [$percent, $em] = $this->bases($property, $styles, $sizing ? ($base['used_styles'] ?? array()) : $parent, $base);
            $actual = self::pixels((string) ($styles[$property] ?? ''));
            $predicted = $factor
                ? (null === $em ? null : (float) $value * $em)
                : CssFluidLength::evaluate($value, $width, (float) ($viewports[$key]['height'] ?? 0), $percent, $em);
            if (null === $actual || null === $predicted || abs($actual - $predicted) > self::TOLERANCE) return null;
            $proven[$key] = $value;
            $observed[(string) ($styles[$property] ?? '')] = true;
        }
        // A unitless line height already equals its pixels at every capture
        // when they agree: only a varying one carries intent pixels lose.
        if ('line-height' === $property && count($observed) < 2 && preg_match('/^\d*\.?\d+$/', (string) reset($proven))) return null;
        return $this->bands($property, $widths, $samples, $proven);
    }

    /**
     * A block box in normal flow whose width the author never declares is
     * `auto`: it fills its parent's content box at every width. Its observed
     * pixels are only that fill at the capture width; frozen, they overflow
     * a narrower viewport and leave a wider one short. Lift the `auto` when
     * no author width wins at any capture or switch point and the observed
     * border box plus margins fills the observed parent's content box at
     * every capture. A document body the evidence does not reach is held to
     * the viewport: the box then fills the viewport, which no content box
     * of the body can exceed.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedAutoWidth(array $widths, array $samples, array $observations, array $viewports, array $parents): ?array
    {
        foreach ($widths + $samples as $width) {
            $declared = $this->declared('width', $width);
            if (null !== $declared && 'auto' !== strtolower($declared)) return null;
        }
        $body = $this->element->parentNode instanceof DOMElement && 'body' === strtolower($this->element->parentNode->nodeName);
        foreach ($widths as $key => $_) {
            $styles = $observations[$key]['used_styles'] ?? array();
            $parent = $parents[$key]['used_styles'] ?? null;
            $box = $observations[$key]['border_box']['width'] ?? null;
            $parentBox = $parents[$key]['border_box']['width'] ?? null;
            if (!is_array($parent) && $body) {
                $parent = array('display' => 'block', 'padding-left' => '0px', 'padding-right' => '0px', 'border-left' => 'none', 'border-right' => 'none');
                $parentBox = $viewports[$key]['width'] ?? null;
            }
            if (!is_array($parent) || !is_numeric($box) || !is_numeric($parentBox) || (float) $box <= 0) return null;
            if (!in_array($styles['display'] ?? '', array('block', 'flow-root', 'flex', 'grid', 'list-item'), true)
                || !in_array($styles['position'] ?? '', array('static', 'relative', 'sticky'), true)
                || 'none' !== ($styles['float'] ?? 'none')) {
                return null;
            }
            if (self::isGrid((string) ($parent['display'] ?? ''))) {
                // A grid item stretches across its grid area, not the container.
                if (!in_array($styles['justify-self'] ?? '', array('auto', 'normal', 'stretch'), true)) return null;
                $fill = self::gridAreaWidth($styles, $parents[$key]) ?? INF;
            } elseif (in_array($parent['display'] ?? '', array('block', 'flow-root', 'list-item'), true)
                || (in_array($parent['display'] ?? '', array('flex', 'inline-flex'), true)
                    && str_starts_with((string) ($parent['flex-direction'] ?? ''), 'column')
                    && in_array($parent['align-items'] ?? '', array('normal', 'stretch'), true)
                    && in_array($styles['align-self'] ?? '', array('auto', 'normal', 'stretch'), true))) {
                // A column flex item stretches across the cross axis like a block.
                $fill = self::contentWidth($parent, (float) $parentBox) ?? INF;
            } else {
                return null;
            }
            $used = (float) $box;
            foreach (array('margin-left', 'margin-right') as $side) {
                $used += self::pixels((string) ($styles[$side] ?? '')) ?? INF;
            }
            if (!is_finite($fill) || !is_finite($used) || abs($fill - $used) > self::TOLERANCE) return null;
        }
        return array(array(null, 'auto'));
    }

    /**
     * Lift a grid's column track list when the author sizes every track by
     * a share of the free space (`1fr`, `minmax(0, 1.3fr)`) or a percentage,
     * and the observed tracks are exactly that share of the observed
     * content box at every capture. Track pixels are only that share at the
     * capture width; the author's list keeps the columns filling the grid.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedGridTracks(array $widths, array $samples, array $observations): ?array
    {
        $proven = array();
        $undeclared = true;
        foreach ($widths + $samples as $width) {
            $declared = $this->declared('grid-template-columns', $width);
            if (null !== $declared && 'none' !== strtolower($declared)) $undeclared = false;
        }
        foreach ($widths as $key => $width) {
            $styles = $observations[$key]['used_styles'] ?? array();
            // No author track list: the single implicit column the capture
            // reports stretches across the content box, as `none` does.
            $value = $undeclared
                && in_array($styles['justify-content'] ?? 'normal', array('normal', 'stretch'), true)
                && in_array(strtolower((string) ($styles['grid-auto-columns'] ?? 'auto')), array('auto', '1fr', 'minmax(0px, 1fr)'), true)
                ? 'none' : $this->declared('grid-template-columns', $width);
            $tracks = 'none' === $value ? array(array('fr', 1.0)) : (null === $value ? null : self::flexibleTracks($value));
            $observed = self::pixelList((string) ($styles['grid-template-columns'] ?? ''));
            $content = self::contentWidth($styles, (float) ($observations[$key]['border_box']['width'] ?? 0));
            $gap = 'normal' === ($styles['column-gap'] ?? 'normal') ? 0.0 : self::pixels((string) ($styles['column-gap'] ?? ''));
            if (!self::isGrid((string) ($styles['display'] ?? '')) || null === $tracks || null === $observed || null === $content || null === $gap
                || count($tracks) !== count($observed)) {
                return null;
            }
            $free = $content - $gap * (count($tracks) - 1);
            $fractions = 0.0;
            foreach ($tracks as [$unit, $amount]) {
                if ('%' === $unit) $free -= $amount / 100 * $content;
                else $fractions += $amount;
            }
            $share = max(0.0, $free) / max(1.0, $fractions);
            foreach ($tracks as $index => [$unit, $amount]) {
                $predicted = '%' === $unit ? $amount / 100 * $content : $amount * $share;
                if (abs($predicted - $observed[$index]) > self::TOLERANCE) return null;
            }
            $proven[$key] = $value;
        }
        if ($undeclared) return array(array(null, 'none'));
        return $this->bands('grid-template-columns', $widths, $samples, $proven);
    }

    /**
     * Tracks of a column list sized only by free-space shares or percentages.
     *
     * @return list<array{0: string, 1: float}>|null [unit ('fr' or '%'), amount] per track.
     */
    private static function flexibleTracks(string $value): ?array
    {
        $value = strtolower(trim($value));
        if (!preg_match_all('/\G\s*(?:minmax\(\s*0(?:px)?\s*,\s*(\d*\.?\d+)fr\s*\)|(\d*\.?\d+)(fr|%))/', $value, $matches, PREG_SET_ORDER)) return null;
        $consumed = implode('', array_column($matches, 0));
        if (trim($consumed) === '' || strlen($consumed) !== strlen(rtrim($value)) ) return null;
        $tracks = array();
        foreach ($matches as $m) {
            $tracks[] = '' !== $m[1] ? array('fr', (float) $m[1]) : array($m[3], (float) $m[2]);
        }
        return $tracks;
    }

    /** @return list<float>|null */
    private static function pixelList(string $value): ?array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: array();
        $list = array();
        foreach ($parts as $part) {
            $px = self::pixels($part);
            if (null === $px) return null;
            $list[] = $px;
        }
        return array() === $list ? null : $list;
    }

    /** A box's content width from its observed border box, padding and borders. */
    private static function contentWidth(array $styles, float $borderBox): ?float
    {
        $width = $borderBox;
        foreach (array('padding-left', 'padding-right') as $side) {
            $width -= self::pixels((string) ($styles[$side] ?? '')) ?? INF;
        }
        foreach (array('border-left', 'border-right') as $side) {
            $width -= self::borderWidth((string) ($styles[$side] ?? '')) ?? INF;
        }
        return is_finite($width) ? $width : null;
    }

    /**
     * The width of the grid area an item occupies: its columns' observed
     * tracks and the gaps between them. Only explicit line numbers, or the
     * sole track of a single-column grid, are read.
     */
    private static function gridAreaWidth(array $styles, array $parentObservation): ?float
    {
        $parent = $parentObservation['used_styles'] ?? array();
        $tracks = self::pixelList((string) ($parent['grid-template-columns'] ?? ''));
        if (null === $tracks) return null;
        $gap = 'normal' === ($parent['column-gap'] ?? 'normal') ? 0.0 : self::pixels((string) ($parent['column-gap'] ?? ''));
        if (null === $gap) return null;
        $start = (string) ($styles['grid-column-start'] ?? 'auto');
        $end = (string) ($styles['grid-column-end'] ?? 'auto');
        if (1 === count($tracks) && in_array($start, array('auto', '1'), true) && in_array($end, array('auto', '2', 'span 1'), true)) {
            return $tracks[0];
        }
        if (!ctype_digit($start) || !ctype_digit($end)) return null;
        $from = (int) $start;
        $to = (int) $end;
        if ($from < 1 || $to <= $from || $to - 1 > count($tracks)) return null;
        return array_sum(array_slice($tracks, $from - 1, $to - $from)) + $gap * ($to - $from - 1);
    }

    private static function isGrid(string $display): bool
    {
        return in_array($display, array('grid', 'inline-grid'), true);
    }

    /** A side border's used width; a border that paints nothing has none. */
    private static function borderWidth(string $value): ?float
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: array();
        if (in_array('none', $tokens, true) || in_array('hidden', $tokens, true)) return 0.0;
        foreach ($tokens as $token) {
            if (null !== ($px = self::pixels($token))) return $px;
        }
        return null;
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
     * value it carries.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @param array<string, string> $proven Proven value by capture key.
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function bands(string $property, array $widths, array $samples, array $proven): ?array
    {
        $points = array();
        foreach ($widths as $key => $width) $points[] = array($width, $proven[$key], true);
        foreach ($samples as $width) {
            $value = $this->declared($property, $width);
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
        return count($covered) === count($bands) ? $bands : null;
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
        $value = $this->withRemInPixels(trim($this->resolver->customProperties->resolveAtViewport($value, $this->element, $width)), $width);
        if ('' === $value || !$fromShorthand) return $value;
        if ('font' === $shorthand) {
            return trim(CssValueInspector::withoutImportant(CssFontShorthand::expand($value, true)[$property] ?? ''));
        }
        $side = self::SIDES[substr($property, strrpos($property, '-') + 1)] ?? null;
        return null === $side ? '' : CssValueInspector::expandBoxShorthand($value)[$side];
    }

    /**
     * Restate `rem` lengths in pixels against the document's root font size
     * at this width. Left unchanged when that size cannot be resolved, so the
     * evaluator rejects the value and the property keeps its pixels.
     */
    private function withRemInPixels(string $value, float $width): string
    {
        if (!preg_match('/\drem\b/i', $value)) return $value;
        $root = $this->rootFontSize($width);
        if (null === $root) return $value;
        return preg_replace_callback(
            '/(?<![\w.-])(\d*\.?\d+)rem\b/i',
            static fn(array $m): string => rtrim(rtrim(number_format((float) $m[1] * $root, 4, '.', ''), '0'), '.') . 'px',
            $value
        ) ?? $value;
    }

    /** The root element's font size the author's cascade gives at this width, in pixels. */
    private function rootFontSize(float $width): ?float
    {
        $key = (string) $width;
        if (array_key_exists($key, $this->rootFontSizes)) return $this->rootFontSizes[$key];
        $root = $this->element->ownerDocument?->documentElement;
        $declared = '';
        if ($root instanceof DOMElement) {
            $winners = $this->resolver->authorViewport->atViewport($root, array('font-size', 'font'), $width);
            $order = array_flip(array_keys($winners));
            $name = isset($order['font']) && (!isset($order['font-size']) || $order['font'] > $order['font-size']) ? 'font' : 'font-size';
            $declared = trim(CssValueInspector::withoutImportant((string) ($winners[$name] ?? '')));
            if ('' !== $declared) $declared = trim($this->resolver->customProperties->resolveAtViewport($declared, $root, $width));
            if ('font' === $name && '' !== $declared) $declared = trim(CssValueInspector::withoutImportant(CssFontShorthand::expand($declared, true)['font-size'] ?? ''));
        }
        // Relative to the user-agent medium size; `rem` on the root is too.
        $size = '' === $declared || 'medium' === strtolower($declared)
            ? 16.0
            : CssFluidLength::evaluate(preg_replace('/(\d)rem\b/i', '$1em', $declared) ?? $declared, $width, 0.0, 16.0, 16.0);
        return $this->rootFontSizes[$key] = null !== $size && $size > 0 ? $size : null;
    }

    /**
     * What `100%` and `1em` resolve to for a property, from observed facts.
     * Width percentages use the parent's content width; other percentage
     * bases (positioned boxes, heights, margins) need layout facts the
     * evidence does not carry, so they stay unknown.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function bases(string $property, array $styles, array $parent, array $parentObservation = array()): array
    {
        $own = self::pixels((string) ($styles['font-size'] ?? ''));
        $inherited = self::pixels((string) ($parent['font-size'] ?? ''));
        if ('font-size' === $property) return array($inherited, $inherited);
        if ('line-height' === $property) return array($own, $own);
        $percent = null;
        // A grid item's percentage width resolves against its grid area.
        if (in_array($property, self::INLINE_PERCENT_PROPERTIES, true) && self::isGrid((string) ($parent['display'] ?? ''))) {
            return array(
                in_array($styles['position'] ?? '', array('absolute', 'fixed'), true) ? null : self::gridAreaWidth($styles, $parentObservation),
                $own,
            );
        }
        if (in_array($property, self::INLINE_PERCENT_PROPERTIES, true)
            && 'content-box' === ($parent['box-sizing'] ?? '')
            && !in_array($styles['position'] ?? '', array('absolute', 'fixed'), true)
            && !in_array($parent['display'] ?? '', array('inline', 'contents'), true)) {
            $percent = self::pixels((string) ($parent['width'] ?? ''));
        }
        return array($percent, $own);
    }

    private static function pixels(string $value): ?float
    {
        return preg_match('/^-?(?:\d*\.)?\d+px$/', $value) ? CssLength::toPx($value) : null;
    }
}

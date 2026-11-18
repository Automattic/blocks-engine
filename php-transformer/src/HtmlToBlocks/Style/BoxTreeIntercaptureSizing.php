<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use DOMElement;

/**
 * Horizontal sizing between two captures (ADR 0005 §4).
 *
 * A carrier states each capture's used pixels, and the widest capture's rule
 * is the base: at a width between two captures, a box keeps the wider
 * capture's pixels. A 1440px-wide page root then stays 1440px wide in a 768px
 * viewport, and everything inside it lays out, and is clipped, as on desktop.
 *
 * Where both neighbouring captures prove the container decided the box's
 * width, the widths strictly between them carry the author's sizing instead
 * of either capture's pixels. The capture widths keep their exact pixels.
 *
 * - `width`: a block-level, non-replaced, unfloated box the author gives no
 *   width (or `auto`) that, at both captures, fills its block container,
 *   stretches across a column flex container or fills its grid area is
 *   `auto`. A fluid author width (`100%`, `calc()`, viewport units) that
 *   evaluates at both captures to the observed width, or to the pixel
 *   `max-width`/`min-width` clamping it, is that expression.
 * - `margin-left` / `margin-right`: an author `auto` whose observed margins,
 *   at both captures, centre the box in its block container stays `auto`.
 * - `grid-template-columns`: author track lists of N equal fractions (or
 *   `none`) whose observed N equal tracks fill the grid at both captures are
 *   the author's list at each width between them.
 *
 * Between the captures the value follows the author's cascade, banded at the
 * author's own breakpoints; a band whose author value is not of the proven
 * kind keeps the pixels.
 */
final class BoxTreeIntercaptureSizing
{
    private const TOLERANCE = 0.5;

    private const BLOCK_CONTAINERS = array('block', 'flow-root', 'list-item');
    private const BLOCK_LEVEL = array('block', 'flow-root', 'list-item', 'flex', 'grid', 'table');
    private const REPLACED = array('img', 'video', 'audio', 'canvas', 'iframe', 'embed', 'object', 'svg', 'input', 'select', 'textarea', 'button', 'picture', 'meter', 'progress');
    private const PROPERTIES = array('width', 'margin-left', 'margin-right', 'grid-template-columns');

    private DOMElement $element;
    private StyleResolver $resolver;
    /** @var array<string, array<string, string>> Author winners by width. */
    private array $winners = array();

    /**
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @param array<string, array<string, mixed>> $parents Captured observations of the element's parent, by viewport key.
     * @return list<array{0: int, 1: int, 2: array<string, string>}> [min-width, max-width, declarations], each strictly between two captures.
     */
    public function lift(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, array $parents, string $sourceTag): array
    {
        if (count($observations) < 2 || in_array(strtolower($sourceTag), self::REPLACED, true)) return array();
        $widths = array();
        foreach ($observations as $key => $observation) {
            if (!isset($viewports[$key]['width']) || !is_array($observation['used_styles'] ?? null) || !is_array($observation['border_box'] ?? null)) return array();
            $widths[$key] = (float) $viewports[$key]['width'];
        }
        arsort($widths);
        $this->element = $element;
        $this->resolver = $resolver;
        $this->winners = array();
        $keys = array_keys($widths);
        $rules = array();
        for ($i = 0; $i + 1 < count($keys); ++$i) {
            [$wide, $narrow] = array($keys[$i], $keys[$i + 1]);
            $min = (int) floor($widths[$narrow]) + 1;
            $max = (int) ceil($widths[$wide]) - 1;
            if ($max < $min) continue;
            $samples = array($min => (float) $min, $max => (float) $max);
            foreach ($resolver->authorViewport->widthBreakpoints() as $breakpoint) {
                foreach (array(floor($breakpoint) - 1, floor($breakpoint), floor($breakpoint) + 1) as $width) {
                    if ($width >= $min && $width <= $max) $samples[(int) $width] = (float) $width;
                }
            }
            ksort($samples);
            $values = array();
            foreach (self::PROPERTIES as $property) {
                if (!$this->proven($property, array($wide, $narrow), $widths, $observations, $viewports, $parents)) continue;
                foreach ($samples as $width => $sample) {
                    $value = $this->bandValue($property, $sample);
                    if (null !== $value) $values[$width][$property] = $value;
                }
            }
            $rules = array_merge($rules, self::bands($samples, $values));
        }
        return $rules;
    }

    /**
     * Group sampled declarations into contiguous width ranges. Every author
     * breakpoint is sampled on both sides, so the cascade cannot switch
     * between two samples: a range runs until the sample that differs.
     *
     * @param array<int, float> $samples Ascending.
     * @param array<int, array<string, string>> $values
     * @return list<array{0: int, 1: int, 2: array<string, string>}>
     */
    private static function bands(array $samples, array $values): array
    {
        $bands = array();
        foreach (array_keys($samples) as $width) {
            $declarations = $values[$width] ?? array();
            ksort($declarations);
            $last = array_key_last($bands);
            if (null !== $last && $bands[$last][2] === $declarations) {
                $bands[$last][1] = $width;
                continue;
            }
            if (null !== $last) $bands[$last][1] = $width - 1;
            $bands[] = array($width, $width, $declarations);
        }
        return array_values(array_filter($bands, static fn(array $band): bool => array() !== $band[2]));
    }

    /** Whether both captures prove the author's kind of value for the property. */
    private function proven(string $property, array $keys, array $widths, array $observations, array $viewports, array $parents): bool
    {
        foreach ($keys as $key) {
            $styles = $observations[$key]['used_styles'];
            $box = $observations[$key]['border_box'];
            $parent = $parents[$key] ?? null;
            $parentStyles = is_array($parent['used_styles'] ?? null) ? $parent['used_styles'] : null;
            $parentBox = is_array($parent['border_box'] ?? null) ? $parent['border_box'] : null;
            $proven = match ($property) {
                'width' => null !== $parentStyles && null !== $parentBox && $this->widthProven($styles, $box, $parentStyles, $parentBox, $widths[$key], (float) ($viewports[$key]['height'] ?? 0)),
                'margin-left', 'margin-right' => null !== $parentStyles && null !== $parentBox && $this->centredProven($property, $styles, $box, $parentStyles, $parentBox, $widths[$key]),
                'grid-template-columns' => $this->tracksProven($styles, $box, $widths[$key]),
                default => false,
            };
            if (!$proven) return false;
        }
        return true;
    }

    private function widthProven(array $styles, array $box, array $parentStyles, array $parentBox, float $width, float $height): bool
    {
        if (!in_array(strtolower((string) ($styles['display'] ?? '')), self::BLOCK_LEVEL, true)) return false;
        if ('none' !== ($styles['float'] ?? 'none')) return false;
        if (!in_array($styles['position'] ?? 'static', array('static', 'relative', 'sticky'), true)) return false;
        $content = self::contentWidth($parentStyles, $parentBox);
        $left = self::px($styles['margin-left'] ?? null);
        $right = self::px($styles['margin-right'] ?? null);
        if (null === $content || null === $left || null === $right || !isset($box['width'])) return false;
        $observed = (float) $box['width'];
        $declared = $this->declared('width', $width);
        if (null === $declared || 'auto' === strtolower($declared)) {
            return $this->fills($styles, $box, $parentStyles, $parentBox, $content, $left, $right);
        }
        if (!self::isFluid($declared)) return false;
        $predicted = CssFluidLength::evaluate($declared, $width, $height, $content, self::px($styles['font-size'] ?? null));
        if (null === $predicted) return false;
        if ('content-box' === ($styles['box-sizing'] ?? 'content-box')) {
            $predicted += self::horizontalPaddingAndBorder($styles) ?? NAN;
        }
        $max = self::px($styles['max-width'] ?? null);
        $min = self::px($styles['min-width'] ?? null);
        if (null !== $max && 'border-box' === ($styles['box-sizing'] ?? '')) $predicted = min($predicted, $max);
        if (null !== $min && 'border-box' === ($styles['box-sizing'] ?? '')) $predicted = max($predicted, $min);
        return abs($predicted - $observed) <= self::TOLERANCE;
    }

    /** Whether an auto-width box's margin box spans what its container gives it. */
    private function fills(array $styles, array $box, array $parentStyles, array $parentBox, float $content, float $left, float $right): bool
    {
        if ('auto' !== ($styles['aspect-ratio'] ?? 'auto')) return false;
        $outer = (float) $box['width'] + $left + $right;
        $display = strtolower((string) ($parentStyles['display'] ?? ''));
        if (in_array($display, self::BLOCK_CONTAINERS, true)) return abs($outer - $content) <= self::TOLERANCE;
        if (in_array($display, array('flex', 'inline-flex'), true)) {
            return in_array(strtolower((string) ($parentStyles['flex-direction'] ?? 'row')), array('column', 'column-reverse'), true)
                && in_array($parentStyles['align-items'] ?? 'normal', array('normal', 'stretch'), true)
                && in_array($styles['align-self'] ?? 'auto', array('auto', 'normal', 'stretch'), true)
                && abs($outer - $content) <= self::TOLERANCE;
        }
        if (!in_array($display, array('grid', 'inline-grid'), true)) return false;
        if (!in_array($styles['justify-self'] ?? 'auto', array('auto', 'normal', 'stretch'), true)
            || !in_array($parentStyles['justify-items'] ?? 'normal', array('normal', 'legacy', 'stretch'), true)) return false;
        $tracks = self::pixelTracks((string) ($parentStyles['grid-template-columns'] ?? ''));
        $gap = self::gap($parentStyles);
        if (null === $tracks || null === $gap) return false;
        $origin = self::contentLeft($parentStyles, $parentBox);
        if (null === $origin || !isset($box['x'])) return false;
        // The margin box must start on a track line and span whole tracks.
        $start = (float) $box['x'] - $left - $origin;
        foreach (self::spans($tracks, $gap) as [$line, $span]) {
            if (abs($start - $line) <= self::TOLERANCE && abs($outer - $span) <= self::TOLERANCE) return true;
        }
        return false;
    }

    /** Whether an author `auto` side margin centres the box at this capture. */
    private function centredProven(string $property, array $styles, array $box, array $parentStyles, array $parentBox, float $width): bool
    {
        if ('auto' !== strtolower((string) $this->declared($property, $width))) return false;
        if (!in_array(strtolower((string) ($parentStyles['display'] ?? '')), self::BLOCK_CONTAINERS, true)) return false;
        if (!in_array(strtolower((string) ($styles['display'] ?? '')), self::BLOCK_LEVEL, true) || 'none' !== ($styles['float'] ?? 'none')) return false;
        if (!in_array($styles['position'] ?? 'static', array('static', 'relative', 'sticky'), true)) return false;
        $content = self::contentWidth($parentStyles, $parentBox);
        $left = self::px($styles['margin-left'] ?? null);
        $right = self::px($styles['margin-right'] ?? null);
        if (null === $content || null === $left || null === $right || !isset($box['width'])) return false;
        $room = max(0.0, $content - (float) $box['width']);
        return abs($left - $right) <= self::TOLERANCE && abs($left + $right - $room) <= self::TOLERANCE;
    }

    private function tracksProven(array $styles, array $box, float $width): bool
    {
        if (!in_array(strtolower((string) ($styles['display'] ?? '')), array('grid', 'inline-grid'), true)) return false;
        $count = self::equalFractionTracks($this->declaredTracks($width));
        $tracks = self::pixelTracks((string) ($styles['grid-template-columns'] ?? ''));
        $gap = self::gap($styles);
        $content = self::contentWidth($styles, $box);
        if (null === $count || null === $tracks || null === $gap || null === $content || count($tracks) !== $count) return false;
        if (max($tracks) - min($tracks) > self::TOLERANCE) return false;
        return abs(array_sum($tracks) + $gap * (count($tracks) - 1) - $content) <= self::TOLERANCE * count($tracks);
    }

    /** The author's value for a proven property at a width between captures, or null to keep the pixels. */
    private function bandValue(string $property, float $width): ?string
    {
        if ('grid-template-columns' === $property) {
            $value = $this->declaredTracks($width);
            return null !== self::equalFractionTracks($value) ? $value : null;
        }
        $declared = $this->declared($property, $width);
        if ('width' === $property) {
            if (null === $declared || 'auto' === strtolower($declared)) return 'auto';
            return self::isFluid($declared) ? $declared : null;
        }
        return null !== $declared && 'auto' === strtolower($declared) ? 'auto' : null;
    }

    /** The author's resting track list at one width; `none` when nothing declares it. */
    private function declaredTracks(float $width): ?string
    {
        $value = $this->declared('grid-template-columns', $width);
        if (null === $value) return 'none';
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    /** The author's resolved value for a longhand at one width; null when nothing declares it. */
    private function declared(string $property, float $width): ?string
    {
        $shorthand = str_starts_with($property, 'margin-') ? 'margin' : null;
        $winners = $this->winners[(string) $width] ??= $this->resolver->authorViewport->atViewport($this->element, array('width', 'margin-left', 'margin-right', 'margin', 'grid-template-columns'), $width);
        $order = array_flip(array_keys($winners));
        $fromShorthand = null !== $shorthand && isset($order[$shorthand]) && (!isset($order[$property]) || $order[$shorthand] > $order[$property]);
        $name = $fromShorthand ? $shorthand : $property;
        if (!isset($winners[$name])) return null;
        $value = trim(CssValueInspector::withoutImportant((string) $winners[$name]));
        if ('' === $value) return null;
        $value = trim($this->resolver->customProperties->resolveAtViewport($value, $this->element, $width));
        if ('' === $value || preg_match('/[{};<>]/', $value)) return null;
        if (!$fromShorthand) return $value;
        return CssValueInspector::expandBoxShorthand($value)['margin-left' === $property ? 3 : 1];
    }

    /** Track count of `none` (1) or N equal `1fr` / `minmax(0, 1fr)` tracks, else null. */
    private static function equalFractionTracks(?string $value): ?int
    {
        if (null === $value) return null;
        if ('none' === $value) return 1;
        $track = '(?:1fr|minmax\(\s*0(?:px)?\s*,\s*1fr\s*\))';
        if (preg_match('/^repeat\(\s*(\d+)\s*,\s*' . $track . '\s*\)$/', $value, $match)) return max(1, (int) $match[1]);
        if (preg_match('/^' . $track . '(?:\s+' . $track . ')*$/', $value)) return (int) preg_match_all('/' . $track . '/', $value);
        return null;
    }

    /** A width the author states relative to the container or viewport. */
    private static function isFluid(string $value): bool
    {
        return 1 === preg_match('/%|\d(?:vw|vh|vmin|vmax)\b|\b(?:calc|clamp|min|max)\(/i', $value)
            && 0 === preg_match('/\bvar\(/i', $value);
    }

    /** @return list<array{0: float, 1: float}> Start line and width of every run of whole consecutive tracks. */
    private static function spans(array $tracks, float $gap): array
    {
        $spans = array();
        $line = 0.0;
        for ($start = 0; $start < count($tracks); ++$start) {
            $width = 0.0;
            for ($end = $start; $end < count($tracks); ++$end) {
                $width += $tracks[$end] + ($end > $start ? $gap : 0.0);
                $spans[] = array($line, $width);
            }
            $line += $tracks[$start] + $gap;
        }
        return $spans;
    }

    private static function contentWidth(array $styles, array $box): ?float
    {
        $edges = self::horizontalPaddingAndBorder($styles);
        return null === $edges || !isset($box['width']) ? null : (float) $box['width'] - $edges;
    }

    /** Left edge of a box's content area, in capture coordinates. */
    private static function contentLeft(array $styles, array $box): ?float
    {
        $padding = self::px($styles['padding-left'] ?? null);
        $tokens = preg_split('/\s+/', strtolower(trim((string) ($styles['border-left'] ?? '')))) ?: array();
        $border = in_array('none', $tokens, true) || in_array('hidden', $tokens, true) ? 0.0 : self::px($tokens[0] ?? null);
        return null === $padding || null === $border || !isset($box['x']) ? null : (float) $box['x'] + $padding + $border;
    }

    private static function horizontalPaddingAndBorder(array $styles): ?float
    {
        $parts = array(self::px($styles['padding-left'] ?? null), self::px($styles['padding-right'] ?? null));
        foreach (array('border-left', 'border-right') as $property) {
            $tokens = preg_split('/\s+/', strtolower(trim((string) ($styles[$property] ?? '')))) ?: array();
            $parts[] = in_array('none', $tokens, true) || in_array('hidden', $tokens, true) ? 0.0 : self::px($tokens[0] ?? null);
        }
        return in_array(null, $parts, true) ? null : array_sum($parts);
    }

    private static function gap(array $styles): ?float
    {
        $gap = $styles['column-gap'] ?? null;
        return 'normal' === $gap ? 0.0 : self::px($gap);
    }

    /** @return list<float>|null */
    private static function pixelTracks(string $value): ?array
    {
        $tracks = array();
        foreach (preg_split('/\s+/', trim($value)) ?: array() as $token) {
            $track = self::px($token);
            if (null === $track) return null;
            $tracks[] = $track;
        }
        return array() === $tracks ? null : $tracks;
    }

    private static function px(mixed $value): ?float
    {
        return is_string($value) && 1 === preg_match('/^-?(?:\d*\.)?\d+px$/', trim($value)) ? CssLength::toPx(trim($value)) : null;
    }
}

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
            $bands = $this->liftedExpression($property, $widths, $samples, $observations, $viewports, $parents)
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
        $observed = array();
        foreach ($widths as $key => $width) {
            $value = $this->declared($property, $width);
            if (null === $value || '' === $value) return null;
            $factor = 'line-height' === $property && preg_match('/^\d*\.?\d+$/', $value);
            if (!$factor && !preg_match('/%|\d(?:vw|vh|vmin|vmax|em)\b|\b(?:calc|clamp|min|max)\(/i', $value)) return null;
            $styles = $observations[$key]['used_styles'] ?? array();
            $parent = $parents[$key]['used_styles'] ?? array();
            [$percent, $em] = $this->bases($property, $styles, $parent);
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
    private function bases(string $property, array $styles, array $parent): array
    {
        $own = self::pixels((string) ($styles['font-size'] ?? ''));
        $inherited = self::pixels((string) ($parent['font-size'] ?? ''));
        if ('font-size' === $property) return array($inherited, $inherited);
        if ('line-height' === $property) return array($own, $own);
        $percent = null;
        if (in_array($property, array('width', 'min-width', 'max-width'), true)
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

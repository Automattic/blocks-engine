<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFontShorthand;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
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

    /**
     * Blocks whose content is the source element's own children, so the size
     * the layout resolves for it is the one the source resolved. Core blocks
     * that rebuild their content (navigation, buttons, images) are not.
     */
    private const SOURCE_CONTENT_BLOCKS = array('core/group', 'core/paragraph', 'core/heading');

    private DOMElement $element;
    private StyleResolver $resolver;
    /** @var list<string> */
    private array $requested = array();
    /** @var array<string, array<string, string>> Cascade winners by width. */
    private array $winners = array();
    /** @var \WeakMap<DOMElement, array<string, array<string, string>>>|null Author declarations by element, then width. */
    private static ?\WeakMap $cascades = null;
    /** @var array<string, float|null> Root font size by width. */
    private array $rootFontSizes = array();

    /**
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @param array<string, array<string, mixed>> $parents Captured observations of the element's parent, by viewport key.
     * @param string $blockName The block the element becomes.
     * @param array<string, array<string, mixed>>|null $containers Captured observations of the box
     *        the element's percentages resolve against, by viewport key: its nearest ancestor that
     *        generates a box. Defaults to the parent.
     * @return array<string, list<array{0: float|null, 1: string}>> Property => bands, widest first:
     *         [max-width or null for the base rule, value].
     */
    public function lift(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, array $parents, string $blockName = '', ?array $containers = null): array
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
        $sourceContent = in_array($blockName, self::SOURCE_CONTENT_BLOCKS, true);
        $lifted = array();
        foreach ($properties as $property) {
            $bands = $this->liftedExpression($property, $widths, $samples, $observations, $viewports, $parents, $containers ?? $parents, $sourceContent)
                ?? ($sourceContent ? $this->liftedLayoutSize($property, $widths, $samples, $observations) : null)
                ?? $this->liftedDisplay($property, $widths, $samples, $observations)
                ?? ($inheriting ? $this->liftedInheritance($property, $widths, $samples, $observations, $parents) : null);
            if (null !== $bands) $lifted[$property] = $bands;
        }
        return $lifted;
    }

    /**
     * The widest width each narrower capture's frozen values hold for.
     *
     * A capture proves its pixels only at its own width. Between two captures
     * the author's cascade decides which one the page is laid out like: up to
     * the first width where the element's or an ancestor's declarations
     * switch away from the narrower capture's, every width resolves the
     * narrower capture's cascade. Its values then hold up to that author
     * breakpoint, not just its own width: a device document a
     * source serves below 1024px, captured at 402px, is that document at
     * 768px too. Where nothing in the chain switches between the captures,
     * the capture's own width stays the bound.
     *
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @return array<string, float> Max-width by viewport key, for every capture but the widest.
     */
    public function captureBounds(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports): array
    {
        $widths = array();
        foreach ($observations as $key => $_) {
            if (!isset($viewports[$key]['width'])) return array();
            $widths[$key] = (float) $viewports[$key]['width'];
        }
        if (count($widths) < 2) return array();
        arsort($widths);
        $keys = array_keys($widths);
        $breakpoints = $resolver->authorViewport->widthBreakpoints();
        $bounds = array();
        foreach (array_slice($keys, 1) as $index => $key) {
            $narrow = $widths[$key];
            $wide = $widths[$keys[$index]];
            $samples = array();
            foreach ($breakpoints as $breakpoint) {
                foreach (array(floor($breakpoint), floor($breakpoint) + 1) as $width) {
                    if ($width > $narrow && $width < $wide) $samples[(string) $width] = $width;
                }
            }
            sort($samples);
            $bounds[$key] = $narrow;
            $switch = null;
            for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
                $own = $this->cascadeAt($node, $resolver, $narrow);
                $held = $narrow;
                foreach (array_merge($samples, array($wide)) as $width) {
                    if ($this->cascadeAt($node, $resolver, $width) !== $own) {
                        $switch = null === $switch ? $held : min($switch, $held);
                        break;
                    }
                    $held = $width;
                }
            }
            if (null !== $switch) $bounds[$key] = $switch;
        }
        return $bounds;
    }

    /**
     * One element's author declarations at one width, memoized per element.
     *
     * @return array<string, string>
     */
    private function cascadeAt(DOMElement $element, StyleResolver $resolver, float $width): array
    {
        self::$cascades ??= new \WeakMap();
        $memo = self::$cascades[$element] ?? array();
        if (!array_key_exists((string) $width, $memo)) {
            $memo[(string) $width] = $resolver->authorViewport->atViewport($element, self::CASCADE_PROPERTIES, $width);
            self::$cascades[$element] = $memo;
        }
        return $memo[(string) $width];
    }

    /** Properties whose author declarations decide which capture a width is laid out like. */
    private const CASCADE_PROPERTIES = array(
        'display', 'position', 'box-sizing', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'gap', 'row-gap', 'column-gap',
        'flex', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content', 'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'order',
        'grid', 'grid-template', 'grid-template-columns', 'grid-template-rows', 'grid-auto-columns', 'grid-auto-rows', 'grid-auto-flow',
        'grid-area', 'grid-row', 'grid-column', 'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end', 'justify-items', 'justify-self',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'top', 'right', 'bottom', 'left', 'float', 'visibility', 'aspect-ratio',
        'font', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'white-space',
    );

    /**
     * @param array<string, float> $widths Capture widths by key, widest first.
     * @param array<string, float> $samples Widths between captures where the cascade can switch.
     * @param bool $intrinsic Whether an intrinsic size keyword resolves for the block as it did in the source.
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedExpression(string $property, array $widths, array $samples, array $observations, array $viewports, array $parents, array $containers, bool $intrinsic = false): ?array
    {
        $proven = array();
        $observed = array();
        $fluid = false;
        foreach ($widths as $key => $width) {
            $value = $this->declared($property, $width);
            if (null === $value || '' === $value) return null;
            // A capture that sized the box by its content needs no proof: its
            // keyword is the intent, as in liftedLayoutSize().
            if ($intrinsic && ('width' === $property || 'height' === $property) && preg_match('/^(?:auto|min-content|max-content|fit-content)$/i', $value)) {
                $proven[$key] = $value;
                $fluid = true;
                continue;
            }
            $factor = 'line-height' === $property && preg_match('/^\d*\.?\d+$/', $value);
            // A fixed length one capture's band declares is proven like any
            // other value; the lift needs a fluid value at some capture.
            if ($factor || preg_match('/%|\d(?:vw|vh|vmin|vmax|em)\b|\b(?:calc|clamp|min|max)\(/i', $value)) $fluid = true;
            elseif (!preg_match('/^-?\d*\.?\d+px$/i', $value)) return null;
            $styles = $observations[$key]['used_styles'] ?? array();
            $parent = $parents[$key]['used_styles'] ?? array();
            [$percent, $em] = $this->bases($property, $styles, $parent, $containers[$key]['used_styles'] ?? array());
            $actual = self::pixels((string) ($styles[$property] ?? ''));
            $predicted = $factor
                ? (null === $em ? null : (float) $value * $em)
                : CssFluidLength::evaluate($value, $width, (float) ($viewports[$key]['height'] ?? 0), $percent, $em);
            if (null === $actual || null === $predicted || abs($actual - $predicted) > self::TOLERANCE) return null;
            $proven[$key] = $value;
            $observed[(string) ($styles[$property] ?? '')] = true;
        }
        if (!$fluid) return null;
        // A unitless line height already equals its pixels at every capture
        // when they agree: only a varying one carries intent pixels lose.
        if ('line-height' === $property && count($observed) < 2 && preg_match('/^\d*\.?\d+$/', (string) reset($proven))) return null;
        return $this->bands($property, $widths, $samples, $proven);
    }

    /**
     * Layout-resolved sizes. Where the author leaves `width` or `height` at
     * `auto` (or undeclared) at every capture and between them, the observed
     * pixels are only what the layout and content resolved at that capture's
     * width. Frozen, they hold the box at that capture's size at every width
     * the band covers, however the content reflows there. A grid track list
     * whose every track is content-sized or flexible (`auto`, `min-content`,
     * `max-content`, `fr`, `minmax(0, fr)`) is the same intent per track: it
     * is lifted when each capture observed exactly the declared tracks.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedLayoutSize(string $property, array $widths, array $samples, array $observations): ?array
    {
        if ('width' === $property || 'height' === $property) {
            foreach ($widths + $samples as $width) {
                $declared = $this->declared($property, $width);
                if (null !== $declared && 'auto' !== strtolower(trim($declared))) return null;
            }
            return array(array(null, 'auto'));
        }
        if ('grid-template-rows' !== $property && 'grid-template-columns' !== $property) return null;
        // No author track list at any capture or between them: the observed
        // tracks are the implicit ones the items' placement and the auto
        // track sizes resolved at that capture's width, so the intent is the
        // initial `none`, as with an undeclared width.
        if ($this->undeclaredEverywhere(array($property, 'grid-template', 'grid'), $widths + $samples)) return array(array(null, 'none'));
        $proven = array();
        foreach ($widths as $key => $width) {
            $declared = (string) $this->declared($property, $width);
            $tracks = self::resolvedTracks($declared);
            if (null === $tracks) return null;
            $observed = trim((string) ($observations[$key]['used_styles'][$property] ?? ''));
            // Every observed track is one the author declared: no implicit or repeated tracks.
            if ('' === $observed || count(preg_split('/\s+/', $observed) ?: array()) !== count($tracks)) return null;
            // As declared: bands() compares it with the declarations between captures.
            $proven[$key] = $declared;
        }
        foreach ($samples as $width) {
            if (null === self::resolvedTracks((string) $this->declared($property, $width))) return null;
        }
        return $this->bands($property, $widths, $samples, $proven);
    }

    /**
     * Whether the author's cascade declares none of these properties at any
     * of the widths.
     *
     * @param list<string> $properties
     * @param array<string, float> $widths
     */
    private function undeclaredEverywhere(array $properties, array $widths): bool
    {
        foreach ($widths as $width) {
            if (array() !== $this->resolver->authorViewport->atViewport($this->element, $properties, $width)) return false;
        }
        return true;
    }

    /**
     * The author's box types. Where the declared `display` is the one each
     * capture rendered, the author's breakpoints decide where it switches,
     * not the capture widths: frozen, a box hidden at one capture keeps the
     * other capture's type up to that capture's width.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedDisplay(string $property, array $widths, array $samples, array $observations): ?array
    {
        if ('display' !== $property) return null;
        $proven = array();
        foreach ($widths as $key => $width) {
            $declared = strtolower(trim((string) $this->declared($property, $width)));
            if ('' === $declared || $declared !== strtolower(trim((string) ($observations[$key]['used_styles'][$property] ?? '')))) return null;
            $proven[$key] = $declared;
        }
        if (count(array_unique($proven)) < 2) return null;
        return $this->bands($property, $widths, $samples, $proven);
    }

    /**
     * The declared tracks when each one sizes the same at any width it is
     * declared for: a content or flexible size (`auto`, `min-content`,
     * `max-content`, `fr`) or `minmax()` from a viewport-only length (`px`,
     * `vw` and CSS math over them) to one of those or to another
     * viewport-only length.
     *
     * @return list<string>|null
     */
    private static function resolvedTracks(string $declared): ?array
    {
        $declared = strtolower(trim($declared));
        if ('' === $declared) return null;
        $sized = '/^(?:auto|min-content|max-content|\d*\.?\d+fr)$/';
        $tracks = CssValueSplitter::splitTopLevelWhitespace($declared);
        foreach ($tracks as $track) {
            if (preg_match($sized, $track)) continue;
            if (!preg_match('/^minmax\((.*)\)$/s', $track, $match)) return null;
            $arguments = CssValueSplitter::splitTopLevel($match[1], array(','));
            if (2 !== count($arguments) || !self::viewportLength($arguments[0])) return null;
            if (!preg_match($sized, trim($arguments[1])) && !self::viewportLength($arguments[1])) return null;
        }
        return array() === $tracks ? null : $tracks;
    }

    /** A length of numbers, `px` and `vw` under CSS math: no percentage, font or custom-property base. */
    private static function viewportLength(string $value): bool
    {
        $value = trim($value);
        return '' !== $value && 1 === preg_match('/\d/', $value)
            && '' === (string) preg_replace('/\b(?:calc|clamp|min|max)\(|\d*\.?\d+(?:px|vw)?\b|[\s()*\/+,-]/', '', $value);
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
     * Width, height and margin percentages use the content box of the
     * container: the nearest ancestor that generates a box (a `display:
     * contents` parent has none). Margins resolve against its width on both
     * axes. A grid item's base is its grid area, not that box; the proof at
     * every capture rejects a value whose base differs. Positioned boxes
     * need layout facts the evidence does not carry, so they stay unknown.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function bases(string $property, array $styles, array $parent, array $container): array
    {
        $own = self::pixels((string) ($styles['font-size'] ?? ''));
        $inherited = self::pixels((string) ($parent['font-size'] ?? ''));
        if ('font-size' === $property) return array($inherited, $inherited);
        if ('line-height' === $property) return array($own, $own);
        $percent = null;
        $axis = match ($property) {
            // Every margin percentage refers to the container's inline size.
            'width', 'min-width', 'max-width', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' => array('width', 'left', 'right'),
            'height', 'min-height', 'max-height' => array('height', 'top', 'bottom'),
            default => null,
        };
        if (null !== $axis
            && !in_array($styles['position'] ?? '', array('absolute', 'fixed'), true)
            && !in_array($container['display'] ?? '', array('inline', 'contents'), true)) {
            $percent = self::contentSize($container, ...$axis);
        }
        return array($percent, $own);
    }

    /**
     * The parent's observed content size on one axis: its used size, less
     * padding and borders when that size is the border box.
     */
    private static function contentSize(array $parent, string $size, string $start, string $end): ?float
    {
        $used = self::pixels((string) ($parent[$size] ?? ''));
        $sizing = $parent['box-sizing'] ?? '';
        if (null === $used || 'content-box' === $sizing) return $used;
        if ('border-box' !== $sizing) return null;
        foreach (array('padding-' . $start, 'padding-' . $end) as $padding) {
            $value = self::pixels((string) ($parent[$padding] ?? ''));
            if (null === $value) return null;
            $used -= $value;
        }
        foreach (array('border-' . $start, 'border-' . $end) as $border) {
            $value = trim((string) ($parent[$border] ?? ''));
            if ('' === $value) return null;
            $tokens = preg_split('/\s+/', $value) ?: array();
            if (in_array('none', $tokens, true) || in_array('hidden', $tokens, true)) continue;
            $width = self::pixels((string) ($tokens[0] ?? ''));
            if (null === $width) return null;
            $used -= $width;
        }
        return $used;
    }

    private static function pixels(string $value): ?float
    {
        return preg_match('/^-?(?:\d*\.)?\d+px$/', $value) ? CssLength::toPx($value) : null;
    }
}


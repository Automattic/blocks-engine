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
 * - A `display` keyword winner (`none`, reached directly or through
 *   `var()`) that equals the observed value at every capture and differs
 *   between captures is lifted, so a box the author hides below a breakpoint
 *   (a menu bar that becomes a hamburger) stops rendering at that
 *   breakpoint rather than at the narrower capture's width. Other keyword
 *   switches only move layout between captures and keep their pixels.
 * - On a heading, an inherited property with no author winner whose observed
 *   value equals the parent's at every capture, and varies between captures,
 *   is lifted as `inherit`. That overrides the heading's user-agent default
 *   and follows the parent at every width. It is limited to headings: their
 *   source parent stays their block parent, while blocks core rebuilds
 *   (navigation items, buttons) sit in wrappers the source never had.
 *
 * - A sizing property (`width`, `height`, `min-*`, `max-*`, `flex-basis`)
 *   whose resting author winner is an intrinsic keyword (`auto`, `none`,
 *   `fit-content`, `min-content`, `max-content`, `stretch`) or absent at
 *   every capture is lifted as that keyword (the initial one when absent).
 *   The source computed its pixels from the same keyword, so this is exact
 *   by construction and needs no proof; declaring the keyword, rather than
 *   omitting the property, also overrides WordPress base and layout styles
 *   (constrained `max-width`, cover `min-height`) the source never had.
 *   Replaced elements and elements sized by an HTML attribute keep pixels:
 *   their intrinsic size is not the author's to restate. An `auto` offset
 *   (`top`/`right`/`bottom`/`left`) whose used pixels a capture reports is
 *   restated the same way.
 * - A `%` height (`height`, `min-height`, `max-height`) is restated as
 *   written, every `var()` expanded. Its basis (the containing block's
 *   height, or `auto`) comes from the same ancestor chain the carriers
 *   rebuild, so source and copy resolve it identically.
 * - A grid track list (`grid-template-*`, `grid-auto-*`) holding any
 *   intrinsic or flexible track (`auto`, content keywords, `fit-content()`,
 *   `minmax()`, `fr`, `%`, viewport units, `repeat(auto-fill|auto-fit, …)`)
 *   is restated as written, every `var()` expanded, rather than as the
 *   resolved track sizes the capture reports. Only a list of fixed lengths
 *   keeps its pixels; an absent one is restated as the initial value.
 *
 * `%` resolves against the observed CSS containing block: the content box
 * of the nearest ancestor that generates a block box (skipping
 * `display: contents` and inline ancestors) for in-flow and relative boxes,
 * the padding box of the nearest positioned ancestor for absolute boxes, and
 * the viewport for fixed boxes. Offsets (`top`, `right`, `bottom`, `left`)
 * lift against it like widths; vertical percentages need a definite
 * containing block height, so only absolute and fixed boxes lift them.
 * Margins and paddings resolve `%` against the containing block's width on
 * every side, and `column-gap` against the element's own content box width,
 * so a `%` box-model winner the author switches in a width query is lifted
 * in that query's band rather than frozen at the widest capture. An `auto`
 * margin the author declared at every capture is restated as `auto`.
 *
 * Where the author's cascade switches between captures (a width query that
 * redefines a scaling token), the lift is a set of bands keyed by the
 * author's own breakpoint, not the capture width. Each band must contain a
 * capture that proves its value; otherwise the property keeps its pixels.
 *
 * A `::before`/`::after` box lifts under the same contract
 * ({@see self::liftPseudo()}): its own author winners, its host as its
 * parent, and `%` against the host's content box (the pseudo's containing
 * block when it is in flow).
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
        'top' => 'inset', 'right' => 'inset', 'bottom' => 'inset', 'left' => 'inset',
        'flex-basis' => 'flex',
        'row-gap' => 'gap', 'column-gap' => 'gap',
    );

    /**
     * Longhands the author can leave to layout => their initial value and the
     * logical or shorthand aliases that also set them (horizontal writing mode).
     */
    private const INTRINSIC = array(
        'width' => array('auto', 'inline-size'),
        'height' => array('auto', 'block-size'),
        'min-width' => array('auto', 'min-inline-size'),
        'min-height' => array('auto', 'min-block-size'),
        'max-width' => array('none', 'max-inline-size'),
        'max-height' => array('none', 'max-block-size'),
        'flex-basis' => array('auto'),
        'grid-template-columns' => array('none', 'grid-template', 'grid'),
        'grid-template-rows' => array('none', 'grid-template', 'grid'),
        'grid-auto-columns' => array('auto', 'grid'),
        'grid-auto-rows' => array('auto', 'grid'),
        'top' => array('auto', 'inset-block', 'inset-block-start'),
        'right' => array('auto', 'inset-inline', 'inset-inline-end'),
        'bottom' => array('auto', 'inset-block', 'inset-block-end'),
        'left' => array('auto', 'inset-inline', 'inset-inline-start'),
    );

    private const GRID_TEMPLATES = array('grid-template-columns', 'grid-template-rows', 'grid-auto-columns', 'grid-auto-rows');

    /** Sizes whose `%` basis the rebuilt ancestor chain reproduces, so the author's `%` is restated as written. */
    private const PERCENT_HEIGHTS = array('height', 'min-height', 'max-height');

    /** Sizing keywords whose used value the layout derives, as the source did. */
    private const INTRINSIC_KEYWORDS = array('auto', 'none', 'fit-content', 'min-content', 'max-content', 'stretch', '-webkit-fill-available', '-moz-available', 'content');

    /** Elements whose `auto` size comes from their content or the user agent, not the author. */
    private const REPLACED = array('img', 'svg', 'video', 'audio', 'canvas', 'iframe', 'object', 'embed', 'picture', 'input', 'select', 'textarea', 'button', 'meter', 'progress');

    private const HORIZONTAL = array('width', 'min-width', 'max-width', 'left', 'right');
    private const VERTICAL = array('height', 'min-height', 'max-height', 'top', 'bottom');
    private const OFFSETS = array('top', 'right', 'bottom', 'left');

    private const SIDES = array('top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3);

    /** Box-model sides whose `%` resolves against the containing block's width, whichever side they are. */
    private const BOX_SIDES = array(
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    );

    /** Margin longhand => the logical aliases that also set it (horizontal writing mode). */
    private const MARGIN_ALIASES = array(
        'margin-top' => array('margin-block', 'margin-block-start'),
        'margin-right' => array('margin-inline', 'margin-inline-end'),
        'margin-bottom' => array('margin-block', 'margin-block-end'),
        'margin-left' => array('margin-inline', 'margin-inline-start'),
    );

    /** Blocks whose carrier may restate an inherited value as `inherit`. */
    private const INHERITING_BLOCKS = array('core/heading');

    private DOMElement $element;
    /** The pseudo-element being lifted (`before`, `after`), or '' for the element itself. */
    private string $pseudo = '';
    private StyleResolver $resolver;
    /** @var list<string> */
    private array $requested = array();
    /** @var array<string, array<string, string>> Cascade winners by width. */
    private array $winners = array();
    /** @var array<string, float|null> Root font size by width. */
    private array $rootFontSizes = array();
    /** @var \Closure(DOMElement): array<string, array<string, mixed>> */
    private \Closure $evidence;
    /** @var array<string, array<string, mixed>> */
    private array $viewports = array();

    /**
     * @param array<string, array<string, mixed>> $observations Captured observations of the element, by viewport key.
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @param array<string, array<string, mixed>> $parents Captured observations of the element's parent, by viewport key.
     * @param string $blockName The block the element becomes.
     * @param (\Closure(DOMElement): array<string, array<string, mixed>>)|null $evidence Captured observations of any
     *        source element, by viewport key; the containing block is looked up through it. Without it only the
     *        parent is known.
     * @return array<string, list<array{0: float|null, 1: string}>> Property => bands, widest first:
     *         [max-width or null for the base rule, value].
     */
    public function lift(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, array $parents, string $blockName = '', ?\Closure $evidence = null): array
    {
        $this->pseudo = '';
        return $this->liftSubject($element, $resolver, $observations, $viewports, $parents, $blockName, $evidence);
    }

    /**
     * Lift an element's `::before` or `::after` box: its own author winners
     * (rules whose subject is that pseudo-element), with the element as its
     * parent, so a `%` resolves against the element's content box and an
     * intrinsic keyword is restated as for the element itself. Nothing is
     * lifted unless the pseudo renders at every capture.
     *
     * @param array<string, array<string, mixed>> $observations Captured observations of the host element, by viewport key.
     * @param string $pseudo `before` or `after`.
     * @return array<string, list<array{0: float|null, 1: string}>> {@see self::lift()}
     */
    public function liftPseudo(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, string $pseudo, ?\Closure $evidence = null): array
    {
        $pseudoObservations = array();
        foreach ($observations as $key => $observation) {
            $styles = $observation['pseudo'][$pseudo]['used_styles'] ?? null;
            if (!is_array($styles) || !is_array($observation['used_styles'] ?? null)) return array();
            $pseudoObservations[$key] = array('used_styles' => $styles);
        }
        $this->pseudo = $pseudo;
        try {
            return $this->liftSubject($element, $resolver, $pseudoObservations, $viewports, $observations, '', $evidence ?? static fn(DOMElement $node): array => $node === $element ? $observations : array());
        } finally {
            $this->pseudo = '';
        }
    }

    /** @see self::lift() */
    private function liftSubject(DOMElement $element, StyleResolver $resolver, array $observations, array $viewports, array $parents, string $blockName, ?\Closure $evidence): array
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
        $this->viewports = $viewports;
        $parentNode = $element->parentNode;
        $this->evidence = $evidence ?? static fn(DOMElement $node): array => $node === $parentNode ? $parents : array();
        $properties = array_keys(reset($observations)['used_styles'] ?? array());
        $aliases = array();
        foreach (array_intersect_key(self::INTRINSIC, array_flip($properties)) as $intrinsic) $aliases = array_merge($aliases, array_slice($intrinsic, 1));
        foreach (array_intersect_key(self::MARGIN_ALIASES, array_flip($properties)) as $margin) $aliases = array_merge($aliases, $margin);
        $this->requested = array_values(array_unique(array_merge($properties, array_values(array_intersect_key(self::SHORTHANDS, array_flip($properties))), $aliases)));
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
            $bands = ('display' === $property ? $this->liftedDisplay($widths, $samples, $observations) : $this->liftedExpression($property, $widths, $samples, $observations, $viewports, $parents))
                ?? $this->liftedIntrinsic($property, $widths, $samples)
                ?? $this->liftedAutoMargin($property, $widths, $samples)
                ?? $this->liftedMixed($property, $widths, $samples, $observations, $viewports, $parents)
                ?? $this->liftedInheritance($property, $widths, $samples, $observations, $parents, $inheriting);
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
            $value = $this->provedExpression($property, (string) $key, $width, $observations, $viewports, $parents);
            if (null === $value) return null;
            $proven[$key] = $value;
            $observed[(string) ($observations[$key]['used_styles'][$property] ?? '')] = true;
        }
        // A unitless line height already equals its pixels at every capture
        // when they agree: only a varying one carries intent pixels lose.
        if ('line-height' === $property && count($observed) < 2 && preg_match('/^\d*\.?\d+$/', (string) reset($proven))) return null;
        return $this->bands($widths, $samples, $proven, function (float $width) use ($property): ?string {
            $value = $this->declared($property, $width);
            return '' === $value ? null : $value;
        });
    }

    /** The author's fluid expression at one capture when it reproduces the captured pixels, or null. */
    private function provedExpression(string $property, string $key, float $width, array $observations, array $viewports, array $parents): ?string
    {
        $value = $this->declared($property, $width);
        if (null === $value || '' === $value) return null;
        $factor = 'line-height' === $property && preg_match('/^\d*\.?\d+$/', $value);
        if (!$factor && !self::expression($value)) return null;
        $styles = $observations[$key]['used_styles'] ?? array();
        $parent = $parents[$key]['used_styles'] ?? array();
        [$percent, $em] = $this->bases($property, $key, $styles, $parent);
        $actual = self::pixels((string) ($styles[$property] ?? ''));
        $predicted = $factor
            ? (null === $em ? null : (float) $value * $em)
            : CssFluidLength::evaluate($value, $width, (float) ($viewports[$key]['height'] ?? 0), $percent, $em);
        if (null === $actual || null === $predicted || abs($actual - $predicted) > self::TOLERANCE) return null;
        return $value;
    }

    /** Whether a value is a viewport-, font- or containing-block-relative expression. */
    private static function expression(string $value): bool
    {
        return 1 === preg_match('/%|\d(?:vw|vh|vmin|vmax|em)\b|\b(?:calc|clamp|min|max)\(/i', $value);
    }

    /**
     * A sizing property whose author value switches kind between author
     * bands (a `%` at the base and `max-content` under a width query): each
     * capture and each author breakpoint between captures takes the first
     * lifter that proves it, and the bands are emitted only when every band
     * holds a capture so proved. Single-kind properties never reach here.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedMixed(string $property, array $widths, array $samples, array $observations, array $viewports, array $parents): ?array
    {
        if (count($widths) < 2 || !isset(self::INTRINSIC[$property]) && !isset(self::MARGIN_ALIASES[$property])) return null;
        $auto = function (float $width) use ($property): ?string {
            if (!isset(self::MARGIN_ALIASES[$property])) return null;
            foreach (self::MARGIN_ALIASES[$property] as $alias) {
                if (null !== $this->declared($alias, $width)) return null;
            }
            return 'auto' === strtolower((string) $this->declared($property, $width)) ? 'auto' : null;
        };
        $intrinsic = fn(float $width): ?string => isset(self::INTRINSIC[$property]) ? $this->intrinsicKeyword($property, $width) : null;
        $expression = function (float $width) use ($property): ?string {
            $value = $this->declared($property, $width);
            return null !== $value && '' !== $value && self::expression($value) ? $value : null;
        };
        $proven = array();
        $kinds = array();
        foreach ($widths as $key => $width) {
            $value = $this->provedExpression($property, (string) $key, $width, $observations, $viewports, $parents);
            $kind = 'expression';
            if (null === $value) {
                $value = $intrinsic($width);
                $kind = 'intrinsic';
            }
            if (null === $value) {
                $value = $auto($width);
                $kind = 'auto';
            }
            if (null === $value) return null;
            $proven[$key] = $value;
            $kinds[$kind] = true;
        }
        if (count($kinds) < 2) return null;
        return $this->bands($widths, $samples, $proven, fn(float $width): ?string => $expression($width) ?? $intrinsic($width) ?? $auto($width));
    }

    /**
     * A sizing property the author left intrinsic (or sized by a layout-owned
     * `%` height or flexible track list) at every capture, and at every author
     * breakpoint between them, restated as the author wrote it.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedIntrinsic(string $property, array $widths, array $samples): ?array
    {
        if (!isset(self::INTRINSIC[$property])) return null;
        $keywords = array();
        foreach ($widths as $key => $width) {
            $keyword = $this->intrinsicKeyword($property, $width);
            if (null === $keyword) return null;
            $keywords[$key] = $keyword;
        }
        return $this->bands($widths, $samples, $keywords, fn(float $width): ?string => $this->intrinsicKeyword($property, $width));
    }

    /**
     * A margin the author set to `auto` at every capture, and at every author
     * breakpoint between them, restated as `auto`: the source computed its
     * pixels from the same keyword and the same containing block. An absent
     * margin keeps its pixels, since its used value may come from the user
     * agent rather than the author.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedAutoMargin(string $property, array $widths, array $samples): ?array
    {
        if (!isset(self::MARGIN_ALIASES[$property])) return null;
        $auto = function (float $width) use ($property): ?string {
            foreach (self::MARGIN_ALIASES[$property] as $alias) {
                if (null !== $this->declared($alias, $width)) return null;
            }
            return 'auto' === strtolower((string) $this->declared($property, $width)) ? 'auto' : null;
        };
        $proven = array();
        foreach ($widths as $key => $width) {
            if (null === $auto($width)) return null;
            $proven[$key] = 'auto';
        }
        return $this->bands($widths, $samples, $proven, $auto);
    }

    /** The intrinsic value the author's cascade resolves a sizing property to at one width, or null. */
    private function intrinsicKeyword(string $property, float $width): ?string
    {
        [$initial] = self::INTRINSIC[$property];
        foreach (array_slice(self::INTRINSIC[$property], 1) as $alias) {
            if (null !== $this->declared($alias, $width)) return null;
        }
        $grid = in_array($property, self::GRID_TEMPLATES, true);
        $offset = in_array($property, self::OFFSETS, true);
        $declared = $this->declared($property, $width);
        $value = null === $declared ? $initial : strtolower($declared);
        if (in_array($value, array('initial', 'unset'), true)) $value = $initial;
        if ($grid) {
            if ($value === $initial) return $initial;
            return self::flexibleTrackList((string) $declared) ? (string) $declared : null;
        }
        if ($offset) return 'auto' === $value ? $value : null;
        if (null !== $declared && in_array($property, self::PERCENT_HEIGHTS, true) && self::percentHeight($declared)) return $declared;
        if (!in_array($value, self::INTRINSIC_KEYWORDS, true)) return null;
        // A replaced or attribute-sized element's intrinsic size is not the
        // author's to restate; a pseudo-element is neither.
        if ('' !== $this->pseudo) return $value;
        if (in_array(strtolower($this->element->tagName), self::REPLACED, true)) return null;
        if (in_array($property, array('width', 'height'), true) && $this->element->hasAttribute($property)) return null;
        return $value;
    }

    /** Whether a resolved height is a percentage the copy resolves against the same basis as the source. */
    private static function percentHeight(string $value): bool
    {
        return 1 === preg_match('/\d%/', $value) && 0 === preg_match('/\bvar\(|\battr\(|\benv\(/i', $value);
    }

    /**
     * Whether a resolved track list holds a track the grid sizes from its own
     * layout or the viewport (intrinsic, flexible, `%` or viewport-relative),
     * so restating it reproduces the tracks the source resolved where the
     * captured pixels would freeze them. A list of fixed lengths keeps its
     * pixels; unresolved references are never restated.
     */
    private static function flexibleTrackList(string $value): bool
    {
        if ('' === trim($value) || preg_match('/\b(?:var|attr|env)\(/i', $value)) return false;
        $tracks = (string) preg_replace('/\[[^\]]*\]/', ' ', strtolower($value));
        return 1 === preg_match('/\b(?:auto|min-content|max-content|fit-content|minmax|auto-fill|auto-fit|subgrid|masonry)\b|\d(?:fr|%|[sld]?v(?:w|h|i|b|min|max))\b|\d%/', $tracks);
    }

    /**
     * The author's `display` keyword, proven when it equals the observed
     * display at every capture. One the captures agree on is already exact;
     * one that switches between them switches at the author's breakpoint.
     *
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedDisplay(array $widths, array $samples, array $observations): ?array
    {
        $proven = array();
        foreach ($widths as $key => $width) {
            $value = strtolower((string) $this->declared('display', $width));
            if (1 !== preg_match('/^[a-z][a-z-]*$/', $value) || in_array($value, array('inherit', 'initial', 'unset', 'revert', 'revert-layer'), true)) return null;
            if ($value !== ($observations[$key]['used_styles']['display'] ?? null)) return null;
            $proven[$key] = $value;
        }
        if (count(array_unique($proven)) < 2) return null;
        return $this->bands($widths, $samples, $proven, function (float $width): ?string {
            $value = $this->declared('display', $width);
            return null === $value || '' === $value ? null : strtolower($value);
        });
    }

    /**
     * @param array<string, float> $widths
     * @param array<string, float> $samples
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function liftedInheritance(string $property, array $widths, array $samples, array $observations, array $parents, bool $allowAbsent): ?array
    {
        if (!in_array($property, BoxTree::INHERITED_PROPERTIES, true) || count($widths) < 2) return null;
        $values = array();
        foreach ($widths + $samples as $key => $width) {
            $declared = $this->declared($property, $width);
            if (null === $declared ? !$allowAbsent : 'inherit' !== strtolower($declared)) return null;
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
     * @param callable(float): ?string $sample The author's value at a width between captures, or null when unresolved.
     * @return list<array{0: float|null, 1: string}>|null
     */
    private function bands(array $widths, array $samples, array $proven, callable $sample): ?array
    {
        $points = array();
        foreach ($widths as $key => $width) $points[] = array($width, $proven[$key], true);
        foreach ($samples as $width) {
            $value = $sample($width);
            if (null === $value) return null;
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
        $winners = $this->winners[(string) $width] ??= $this->resolver->authorViewport->atViewport($this->element, $this->requested, $width, pseudoElement: $this->pseudo);
        $order = array_flip(array_keys($winners));
        $shorthand = self::SHORTHANDS[$property] ?? null;
        $fromShorthand = null !== $shorthand && isset($order[$shorthand]) && (!isset($order[$property]) || $order[$shorthand] > $order[$property]);
        $name = $fromShorthand ? $shorthand : $property;
        if (!isset($winners[$name])) return null;
        $value = trim(CssValueInspector::withoutImportant((string) $winners[$name]));
        if ('' === $value) return null;
        if ('' !== $this->pseudo && $this->pseudoDefinesReferenced($value, $width)) return '';
        if ($this->resolver->customProperties->isProvenInvalid($value, $this->element)) {
            // Invalid at computed-value time means unset for the whole
            // declaration, including every longhand of a shorthand.
            return in_array($property, BoxTree::INHERITED_PROPERTIES, true) ? 'inherit' : 'unset';
        }
        $value = $this->withRemInPixels(trim($this->resolver->customProperties->resolveAtViewport($value, $this->element, $width)), $width);
        if ('' === $value || !$fromShorthand) return $value;
        if ('font' === $shorthand) {
            return trim(CssValueInspector::withoutImportant(CssFontShorthand::expand($value, true)[$property] ?? ''));
        }
        if ('flex' === $shorthand) return '';
        // `gap` is `<row-gap> <column-gap>?`, the first two box sides.
        if ('gap' === $shorthand) return CssValueInspector::expandBoxShorthand($value)['row-gap' === $property ? 0 : 1];
        $side = self::SIDES['inset' === $shorthand ? $property : substr($property, (int) strrpos($property, '-') + 1)] ?? null;
        return null === $side ? '' : CssValueInspector::expandBoxShorthand($value)[$side];
    }

    /**
     * Whether the pseudo-element itself defines a custom property the value
     * references. The resolver expands `var()` from the host outward, which
     * would miss that definition, so the value is left unresolved instead.
     */
    private function pseudoDefinesReferenced(string $value, float $width): bool
    {
        if (!preg_match_all('/var\(\s*(--[\w-]+)/', $value, $names)) return false;
        $names = array_values(array_unique($names[1]));
        return array() !== $this->resolver->authorViewport->atViewport($this->element, $names, $width, pseudoElement: $this->pseudo);
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
     * Percentages of sizes and offsets use the observed containing block;
     * margins and paddings use its width on every side; `column-gap` uses the
     * element's own content box width. Other percentage bases (in-flow
     * heights, `row-gap`) stay unknown.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function bases(string $property, string $key, array $styles, array $parent): array
    {
        $own = self::pixels((string) ($styles['font-size'] ?? ''));
        $inherited = self::pixels((string) ($parent['font-size'] ?? ''));
        if ('font-size' === $property) return array($inherited, $inherited);
        if ('line-height' === $property) return array($own, $own);
        $position = strtolower((string) ($styles['position'] ?? 'static'));
        $outOfFlow = in_array($position, array('absolute', 'fixed'), true);
        if (in_array($property, self::BOX_SIDES, true)) {
            $block = $this->containingBlock($key, $position);
            return array(null === $block ? null : $block[0], $own);
        }
        if ('column-gap' === $property) {
            $box = self::box($styles, true);
            return array(null === $box ? null : $box[0], $own);
        }
        $horizontal = in_array($property, self::HORIZONTAL, true);
        if (!$horizontal && !in_array($property, self::VERTICAL, true)) return array(null, $own);
        if (in_array($property, self::OFFSETS, true) && !$outOfFlow && 'relative' !== $position) return array(null, $own);
        // An in-flow percentage height or vertical offset needs a definite
        // containing block height the carriers no longer freeze.
        if (!$horizontal && !$outOfFlow) return array(null, $own);
        $block = $this->containingBlock($key, $position);
        return array(null === $block ? null : $block[$horizontal ? 0 : 1], $own);
    }

    /**
     * The observed containing block of the element at one capture, as
     * [width, height] in pixels, or null when the evidence cannot prove it.
     *
     * @return array{0: float, 1: float}|null
     */
    private function containingBlock(string $key, string $position): ?array
    {
        if ('fixed' === $position) {
            $viewport = $this->viewports[$key] ?? array();
            return isset($viewport['width'], $viewport['height']) ? array((float) $viewport['width'], (float) $viewport['height']) : null;
        }
        $absolute = 'absolute' === $position;
        // A pseudo-element's box is a child of its host's.
        for ($node = '' === $this->pseudo ? $this->element->parentNode : $this->element; $node instanceof DOMElement; $node = $node->parentNode) {
            $styles = ($this->evidence)($node)[$key]['used_styles'] ?? null;
            if (!is_array($styles)) {
                // The document root boxes are rarely captured; an absolute
                // box proves the initial containing block below instead.
                if ($absolute && in_array(strtolower($node->tagName), array('body', 'html'), true)) continue;
                return null;
            }
            $display = strtolower((string) ($styles['display'] ?? ''));
            // `display: contents` generates no box, so it contains nothing.
            if ('contents' === $display) continue;
            if ($absolute) {
                if (!self::containsPositioned($styles)) continue;
                // An inline containing block is the box around its fragments.
                return 'inline' === $display ? null : self::box($styles, false);
            }
            if ('inline' !== $display) return self::box($styles, true);
        }
        // An absolute box with no positioned ancestor uses the initial containing block.
        $viewport = $this->viewports[$key] ?? array();
        return $absolute && isset($viewport['width'], $viewport['height']) ? array((float) $viewport['width'], (float) $viewport['height']) : null;
    }

    /** Whether a box is the containing block of its absolutely positioned descendants. */
    private static function containsPositioned(array $styles): bool
    {
        if ('static' !== strtolower((string) ($styles['position'] ?? 'static'))) return true;
        foreach (array('transform', 'filter', 'backdrop-filter') as $property) {
            $value = strtolower(trim((string) ($styles[$property] ?? 'none')));
            if ('' !== $value && 'none' !== $value) return true;
        }
        return false;
    }

    /**
     * An observed box's content box (or padding box) as [width, height].
     *
     * @return array{0: float, 1: float}|null
     */
    private static function box(array $styles, bool $content): ?array
    {
        $borderBox = 'border-box' === strtolower((string) ($styles['box-sizing'] ?? 'content-box'));
        $size = array();
        foreach (array(array('width', 'left', 'right'), array('height', 'top', 'bottom')) as [$dimension, $start, $end]) {
            $value = self::pixels((string) ($styles[$dimension] ?? ''));
            if (null === $value) return null;
            $padding = 0.0;
            $border = 0.0;
            // A content-box size is the content box: padding and borders
            // only matter when converting to the other box.
            if ($borderBox || !$content) {
                foreach (array($start, $end) as $side) {
                    $sidePadding = self::pixels((string) ($styles['padding-' . $side] ?? '0px'));
                    $sideBorder = self::borderWidth((string) ($styles['border-' . $side] ?? 'none'));
                    if (null === $sidePadding || null === $sideBorder) return null;
                    $padding += $sidePadding;
                    $border += $sideBorder;
                }
            }
            $size[] = $borderBox ? ($content ? $value - $padding - $border : $value - $border) : ($content ? $value : $value + $padding);
        }
        return array($size[0], $size[1]);
    }

    /** The width of an observed border side shorthand (`1px solid red`, `none`). */
    private static function borderWidth(string $value): ?float
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: array();
        if (in_array('none', $tokens, true) || in_array('hidden', $tokens, true)) return 0.0;
        foreach ($tokens as $token) {
            if (preg_match('/^(?:\d*\.)?\d+px$/', $token)) return CssLength::toPx($token);
        }
        return null;
    }

    private static function pixels(string $value): ?float
    {
        return preg_match('/^-?(?:\d*\.)?\d+px$/', $value) ? CssLength::toPx($value) : null;
    }
}

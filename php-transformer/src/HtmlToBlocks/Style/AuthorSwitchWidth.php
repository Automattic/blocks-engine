<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/**
 * Where the author's own cascade switches a property between two captures.
 *
 * A narrower capture's observed value is evidence for the author rule that
 * produced it, and that rule applies from the author's breakpoint down, not
 * only below the capture's width. A capture taken at 402px of a value the
 * author declares inside `@media (max-width:1023px)` holds at 768px too;
 * scoping it to `(max-width:402px)` leaves every width between the two
 * captures on the wider capture's layout.
 *
 * The switch is read from the author's declarations for the property and
 * every shorthand or alias that also sets it, sampled on both sides of each
 * author breakpoint between the captures, every `var()` resolved at the
 * sampled width. The narrower capture's value is proven up to the last width
 * that still resolves the declarations it was captured under. When they
 * never change before the wider capture, the difference is not the author's
 * switch and the capture width stays the boundary.
 */
final class AuthorSwitchWidth
{
    /** Longhand => the shorthands and aliases that also set it (horizontal writing mode). */
    private const FAMILIES = array(
        'grid-row-start' => array('grid-row', 'grid-area', 'grid'),
        'grid-row-end' => array('grid-row', 'grid-area', 'grid'),
        'grid-column-start' => array('grid-column', 'grid-area', 'grid'),
        'grid-column-end' => array('grid-column', 'grid-area', 'grid'),
        'grid-template-columns' => array('grid-template', 'grid'),
        'grid-template-rows' => array('grid-template', 'grid'),
        'grid-template-areas' => array('grid-template', 'grid'),
        'grid-auto-columns' => array('grid'),
        'grid-auto-rows' => array('grid'),
        'grid-auto-flow' => array('grid'),
        'row-gap' => array('gap', 'grid-gap', 'grid-row-gap'),
        'column-gap' => array('gap', 'grid-gap', 'grid-column-gap'),
        'margin-top' => array('margin', 'margin-block', 'margin-block-start'),
        'margin-right' => array('margin', 'margin-inline', 'margin-inline-end'),
        'margin-bottom' => array('margin', 'margin-block', 'margin-block-end'),
        'margin-left' => array('margin', 'margin-inline', 'margin-inline-start'),
        'padding-top' => array('padding', 'padding-block', 'padding-block-start'),
        'padding-right' => array('padding', 'padding-inline', 'padding-inline-end'),
        'padding-bottom' => array('padding', 'padding-block', 'padding-block-end'),
        'padding-left' => array('padding', 'padding-inline', 'padding-inline-start'),
        'top' => array('inset', 'inset-block', 'inset-block-start'),
        'right' => array('inset', 'inset-inline', 'inset-inline-end'),
        'bottom' => array('inset', 'inset-block', 'inset-block-end'),
        'left' => array('inset', 'inset-inline', 'inset-inline-start'),
        'width' => array('inline-size'),
        'height' => array('block-size'),
        'min-width' => array('min-inline-size'),
        'min-height' => array('min-block-size'),
        'max-width' => array('max-inline-size'),
        'max-height' => array('max-block-size'),
        'flex-direction' => array('flex-flow'),
        'flex-wrap' => array('flex-flow'),
        'flex-grow' => array('flex'),
        'flex-shrink' => array('flex'),
        'flex-basis' => array('flex'),
        'align-items' => array('place-items'),
        'justify-items' => array('place-items'),
        'align-self' => array('place-self'),
        'justify-self' => array('place-self'),
        'align-content' => array('place-content'),
        'justify-content' => array('place-content'),
        'font-size' => array('font'),
        'font-family' => array('font'),
        'font-weight' => array('font'),
        'font-style' => array('font'),
        'line-height' => array('font'),
        'overflow-x' => array('overflow'),
        'overflow-y' => array('overflow'),
        'background-color' => array('background'),
        'background-image' => array('background'),
        'border-radius' => array('border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius'),
    );

    /** @var array<string, array<string, string>> Author winners by sampled width. */
    private array $winners = array();
    /** @var list<string> */
    private array $requested;

    /** @param list<string> $properties The properties a carrier may restate for the element. */
    public function __construct(
        private readonly DOMElement $element,
        private readonly StyleResolver $resolver,
        array $properties
    ) {
        $requested = array();
        foreach ($properties as $property) {
            $requested[$property] = true;
            foreach (self::FAMILIES[$property] ?? array() as $alias) $requested[$alias] = true;
        }
        $this->requested = array_keys($requested);
    }

    /**
     * The widest viewport, in pixels, at which the author's cascade still
     * resolves a property as it does at the narrower capture, when it
     * switches before the wider capture; null otherwise.
     */
    public function boundary(string $property, float $narrow, float $wide): ?float
    {
        if ($wide <= $narrow) return null;
        $points = array((string) $narrow => $narrow, (string) $wide => $wide);
        foreach ($this->resolver->authorViewport->widthBreakpoints() as $breakpoint) {
            $floor = floor($breakpoint);
            foreach (array($floor - 1, $floor, $floor + 1) as $width) {
                if ($width > $narrow && $width < $wide) $points[(string) $width] = $width;
            }
        }
        sort($points);
        $names = array_merge(array($property), self::FAMILIES[$property] ?? array());
        $captured = $this->winning($names, $narrow);
        if (!$this->widthInvariant($property, $captured)) return null;
        $edge = $narrow;
        foreach ($points as $width) {
            if ($this->winning($names, $width) !== $captured) return $edge;
            $edge = $width;
        }
        return null;
    }

    /**
     * Whether the declarations the narrower capture resolved under compute
     * the same used value at every width that resolves them. A length the
     * author states relative to the viewport, a container or the content
     * (`%`, `vw`, `em`, `fr`, `auto`, a math function) was observed at the
     * capture width only, so its pixel used value cannot be stretched to the
     * author's breakpoint. Nothing declared proves nothing.
     *
     * @param array<string, string> $declared
     */
    private function widthInvariant(string $property, array $declared): bool
    {
        if (array() === $declared) return false;
        $length = 1 === preg_match('/^(?:(?:min-|max-)?(?:width|height)|margin|padding|top|right|bottom|left|inset|(?:row-|column-)?gap|font-size|line-height|letter-spacing|word-spacing|text-indent|flex-basis|grid-template-|grid-auto-(?:rows|columns)|border-radius)/', $property);
        foreach ($declared as $value) {
            $value = strtolower(trim($value));
            if (str_contains($value, 'var(') || '' === $value) return false;
            if ($length && 1 === preg_match('/%|\d(?:[a-z]*v[whib]|vmin|vmax|cq[a-z]*|r?em|ex|ch|r?lh|fr)\b|\b(?:auto|fit-content|min-content|max-content|stretch|normal|calc|min|max|clamp|minmax|repeat|inherit|initial|unset|revert)\b/', $value)) return false;
        }
        return true;
    }

    /**
     * @param list<string> $names
     * @return array<string, string>
     */
    private function winning(array $names, float $width): array
    {
        $winners = $this->winners[(string) $width] ??= $this->resolver->authorViewport->atViewport($this->element, $this->requested, $width);
        $declared = array();
        foreach ($names as $name) {
            if (!isset($winners[$name])) continue;
            // A custom property the author redefines in a width query switches the value too.
            $value = (string) $winners[$name];
            $declared[$name] = str_contains($value, 'var(') ? $this->resolver->customProperties->resolveAtViewport($value, $this->element, $width) : $value;
        }
        return $declared;
    }
}

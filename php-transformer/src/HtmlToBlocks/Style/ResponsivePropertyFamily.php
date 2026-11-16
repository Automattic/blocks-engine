<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * The property family a responsive declaration competes in: a breakpoint
 * that restates one member can conflict with an unconditional value of any
 * other member, and with nothing outside the family.
 */
final class ResponsivePropertyFamily
{
    public static function of(string $property): string
    {
        $property = strtolower(trim($property));
        // `gap`/`row-gap`/`column-gap` are a genuine shorthand/longhand family
        // (setting one can affect the others) but they do not cascade-interact
        // with `display`, `justify-content`, `align-*`, or `flex-*`/`grid-*`:
        // a responsive breakpoint that only toggles `display` cannot conflict
        // with an unconditional `gap` value baked in from the base state.
        // Folding gap into the broader `layout` family below caused an
        // unrelated conditional `display` rule (e.g. `.md\:flex{display:flex}`)
        // to also strip an unconditional `gap` declaration (e.g.
        // `.gap-8{gap:...}`), silently dropping the authored gap for any
        // responsive-hidden flex/grid container.
        if (in_array($property, array('gap', 'row-gap', 'column-gap'), true)) {
            return 'gap';
        }
        if (
            in_array($property, array('display', 'justify-content', 'align-content', 'align-items', 'align-self'), true)
            || str_starts_with($property, 'flex-')
            || str_starts_with($property, 'grid-')
        ) {
            return 'layout';
        }
        // `border` and `border-width/style/color` never set a corner radius, so
        // a breakpoint that only restates the border must not claim it either.
        if (1 === preg_match('/^border(?:-(?:top|bottom|start|end)-(?:left|right|start|end))?-radius$/', $property)) {
            return 'border-radius';
        }
        foreach (array('padding', 'margin', 'border', 'background') as $family) {
            if ($property === $family || str_starts_with($property, $family . '-')) {
                return $family;
            }
        }

        return $property;
    }
}

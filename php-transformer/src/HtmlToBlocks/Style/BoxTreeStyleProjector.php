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
     */
    public function project(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents = array(), string $blockName = '', array $fluid = array()): array
    {
        if (array() === $observations) return $attrs;
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
        $css = $this->carrierCss(array_diff_key($base, $consumed), $keys, $declarations, $status, $observations, $viewports, $bands, true);
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
     * Restyle an inline element inside RichText, which has no block
     * attributes: its observed typography that differs from its source
     * parent's goes entirely to a carrier class. A value equal to the
     * parent's is left to inheritance, so a lifted fluid block value keeps
     * reaching it between captures.
     *
     * @param array<string, list<array{0: float|null, 1: string}>> $fluid {@see BoxTreeFluidIntent::lift()}
     * @return string The carrier class, or '' when the inline inherits everything observed.
     */
    public function inlineCarrier(array $observations, array $viewports, LayoutGeometryState $state, array $parents, array $fluid = array()): string
    {
        $properties = array_flip(self::INLINE_TYPOGRAPHY);
        $typography = array();
        foreach ($observations as $key => $observation) {
            if (!isset($viewports[$key]['width']) || !is_array($observation['used_styles'] ?? null)) continue;
            $typography[$key] = array('used_styles' => array_intersect_key($observation['used_styles'], $properties));
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
        $bands = $this->liftFluid($declarations, $keys, array_intersect_key($fluid, $properties));
        return $this->carrier($this->carrierCss($declarations[$keys[0]], $keys, $declarations, $status, $typography, $viewports, $bands, false), $state);
    }

    /**
     * Inherited typography an inline can set apart from its block. Color
     * stays with the link and state styles that already own it.
     */
    private const INLINE_TYPOGRAPHY = array('font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform');

    /**
     * Author intent proven at every capture (BoxTreeFluidIntent) replaces
     * the frozen pixels in every capture, so it needs no capture delta;
     * where the author switches it, its bands follow the author's own
     * breakpoints. Do not resurrect properties pruned at any viewport.
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
            foreach ($keys as $key) {
                if (!isset($declarations[$key][$property])) continue 2;
            }
            foreach ($keys as $key) $declarations[$key][$property] = $propertyBands[0][1];
            foreach (array_slice($propertyBands, 1) as [$maxWidth, $value]) {
                $bands[self::width((float) $maxWidth)][$property] = $value;
            }
        }
        return $bands;
    }

    /** The base rule, then each narrower capture's delta, then the lifted bands. */
    private function carrierCss(array $base, array $keys, array $declarations, array $status, array $observations, array $viewports, array $bands, bool $pseudos): string
    {
        $baseKey = $keys[0];
        $css = $this->rule(self::SELECTOR, $base);
        $effective = $declarations[$baseKey];
        $pseudoEffective = array();
        if ($pseudos) $css .= $this->pseudoRules($observations[$baseKey], $pseudoEffective);
        foreach (array_slice($keys, 1) as $key) {
            $delta = $this->delta($effective, $declarations[$key], $status[$key], $observations[$key]['used_styles']);
            $effective = array_replace($effective, $delta);
            $pseudo = $pseudos ? $this->pseudoRules($observations[$key], $pseudoEffective) : '';
            if (array() === $delta && '' === $pseudo) continue;
            $css .= '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $this->rule(self::SELECTOR, $delta) . $pseudo . '}';
        }
        // Numeric keys become ints: compare them as widths, narrowest last.
        uksort($bands, static fn(int|string $a, int|string $b): int => (float) $b <=> (float) $a);
        foreach ($bands as $maxWidth => $declared) {
            $css .= '@media (max-width:' . $maxWidth . 'px){' . $this->rule(self::SELECTOR, $declared) . '}';
        }
        return $css;
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

    /** @param array<string, array<string, string>> $effective Pseudo declarations already active, by pseudo name. */
    private function pseudoRules(array $observation, array &$effective): string
    {
        $css = '';
        foreach (array('before', 'after') as $name) {
            $pseudo = $observation['pseudo'][$name] ?? null;
            if (null !== $pseudo) {
                // Pseudos inherit from their originating element, not its parent.
                $pseudoStatus = $this->pruner->classify($pseudo['used_styles'], $observation['used_styles']);
                $declarations = array_merge($this->kept($pseudo['used_styles'], $pseudoStatus), array('content' => $pseudo['content']));
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

    private function rule(string $selector, array $styles): string
    {
        $declarations = array();
        foreach (self::withUniformBorder($styles) as $name => $value) {
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

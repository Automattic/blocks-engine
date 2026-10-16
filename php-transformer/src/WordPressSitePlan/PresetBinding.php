<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * Binds block colour, font size, font family and padding values that match a
 * design-system token to WordPress presets, so editing the token in Global
 * Styles restyles the block.
 *
 * - Text colour becomes `textColor` (`has-text-color has-{slug}-color`).
 * - Background colour becomes `backgroundColor`
 *   (`has-background has-{slug}-background-color`).
 * - Font size becomes `fontSize` (`has-{slug}-font-size`).
 * - Font family becomes `fontFamily` (`has-{slug}-font-family`).
 * - Padding, margin and block gap become `var:preset|spacing|{slug}`.
 *
 * A value reaches a block in one of two ways, and both are bound:
 *
 * - Literal: the conversion serialized it as a block style attribute with a
 *   matching inline declaration. The inline declaration is removed (or, for
 *   spacing, rewritten to the preset variable) so the saved markup is what
 *   core's save function produces for the new attributes. A `var()` literal is
 *   resolved through custom properties set once, unconditionally, on the
 *   document root, or else through the value the source rendered for the
 *   block's class list.
 * - Class-driven: the block carries the author's classes and an author rule
 *   for those classes sets the value; the block has no attribute for it. The
 *   source documents record, per class list, what the elements carrying it
 *   rendered (`class_styles` in `blocks-engine/design-usage/v1`). When every
 *   element with that class list rendered the same value and it matches a
 *   token, the preset attribute and its class are added. Padding is added as
 *   an inline preset variable, which is what core saves for it.
 *
 * The author class stays on the block, and so does its projected rule: a
 * utility class usually sets more than the bound property (`text-sm` also
 * sets `line-height`). The preset wins without removing it: core's preset
 * classes are `!important`, and a spacing preset is inline. What could still
 * override a preset is another `!important` rule, which is why binding is
 * refused when one can reach the element. The projected native-button rules
 * that restate author classes on `.wp-block-button__link` with `!important`
 * step aside for `.has-text-color` and `.has-background` (see
 * AuthorStylesheetProjector and GeneratedBlockStyleProjector), the same way
 * they step aside for an inline value, so they do not block colour binding.
 * Button typography is not bound: core serializes it on the link, where
 * those rules reset `font` for every value that is not inline.
 *
 * Each block is rewritten on its own, from its delimiter and its own opening
 * tag, so the rewrite gives the same result on a whole page and on any
 * fragment that contains the block. It is idempotent: a bound value is no
 * longer a literal and a block that has a preset attribute is not rebound.
 *
 * A value is left alone when binding could change what renders:
 *
 * - the inline declaration is not exactly the attribute value WordPress
 *   serialized (the markup was not produced from these attributes);
 * - re-encoding the delimiter JSON would not reproduce it byte for byte;
 * - a stylesheet declares the property `!important` for a selector whose
 *   subject this block could match, unless the selector excludes the classes
 *   the bound block carries;
 * - a stylesheet selects on the inline style text (`[style*=…]`) for that
 *   property in a way the rewrite would change;
 * - a `:hover`, `:focus`, `:focus-visible`, `:focus-within` or `:active`
 *   rule sets the property on the block's classes or tags, flat
 *   (`.hover\:bg-x:hover`) or nested (`.hover\:bg-x{&:hover{…}}`);
 * - the source renders the property differently under a media condition
 *   (breakpoint or colour scheme), unless every such value matches the same
 *   token;
 * - elements with the block's class list render different values.
 */
final class PresetBinding
{
    /** Blocks whose colour, font-size and spacing support serializes on the wrapper. */
    private const WRAPPER_BLOCKS = array('paragraph', 'heading', 'group', 'columns', 'column', 'list', 'list-item', 'quote', 'buttons');

    /** core/button serializes colour and padding on its inner link. */
    private const LINK_BLOCKS = array('button');

    /** Supports a block type has for values it carries only through classes. */
    private const CLASS_SUPPORTS = array(
        'paragraph' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'heading' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'group' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'list' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'quote' => array('text', 'background', 'fontSize', 'fontFamily'),
        'columns' => array('text', 'background', 'padding'),
        'column' => array('text', 'background', 'padding'),
        'button' => array('text', 'background', 'padding'),
    );

    /** Properties the source records per class list (see DesignUsageCollector). */
    private const CLASS_STYLE_PROPERTIES = array('color', 'background-color', 'font-size', 'font-family', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left');

    /** Classes WordPress or the engine add to a block; they do not identify the author's class list. */
    private const GENERATED_CLASS = '/^(?:wp-|has-|is-layout-|is-content-justification-|is-nowrap$|is-vertical$|blocks-engine-|align(?:wide|full|left|right|center)$)/';

    private const SPACING_SIDES = array('top', 'right', 'bottom', 'left');

    private const STATE_PSEUDO = '/:(?:hover|focus-visible|focus-within|focus|active)\b/i';

    /** @var array<string,list<array{tokens:list<string>,negated:list<string>}>> `!important` rules per property family. */
    private array $importantRules = array();
    /** @var array<string,list<array{tokens:list<string>|null,negated:list<string>,fragment:string}>> `[style*=…]` selectors per family; null tokens reach any element. */
    private array $styleTextRules = array();
    /** @var array<string,array<string,true>> Tokens of elements an interaction-state rule restyles, per family; `*` is any element. */
    private array $stateTokens = array();
    /** @var array<string,array<string,string>> Authored class list => property => the one observation every element agreed on, or `!`. */
    private array $classStyles = array();
    private int $bound = 0;
    /** @var array<string,string|false> Root custom properties; false when a value is scoped or conditional. */
    private array $variables = array();
    /** @var array<string,array<string,true>> Values custom properties take outside the root. */
    private array $scopedVariables = array();
    /** @var array<string,string> Custom properties every definition, all outside the root, sets to one value. */
    private array $uniformVariables = array();

    /**
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $usageBySource Design usage keyed by document source path.
     */
    public function __construct(private readonly DesignSystem $system, array $assets, array $usageBySource = array())
    {
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], function (string $prelude, string $body, array $ancestors = array()): void {
                $this->indexRule($prelude, $body);
                $this->indexVariables($prelude, $body, $ancestors);
            });
        }
        foreach ($this->scopedVariables as $name => $values) {
            if (!array_key_exists($name, $this->variables) && 1 === count($values)) $this->uniformVariables[$name] = (string) array_key_first($values);
            if (is_string($this->variables[$name] ?? null) && array($this->variables[$name] => true) !== $values) $this->variables[$name] = false;
            if (!array_key_exists($name, $this->variables)) $this->variables[$name] = false;
        }
        $this->classStyles = self::mergeClassStyles($usageBySource);
    }

    /** Number of values bound to presets so far. */
    public function boundCount(): int
    {
        return $this->bound;
    }

    public function bind(string $markup): string
    {
        if (!str_contains($markup, '<!-- wp:')) return $markup;
        $pattern = '/<!-- wp:(' . implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), array_merge(self::WRAPPER_BLOCKS, self::LINK_BLOCKS))) . ') (\{.*?\}) (\/)?-->/s';
        $result = '';
        $offset = 0;
        while (1 === preg_match($pattern, $markup, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            $result .= substr($markup, $offset, $start - $offset);
            $offset = $end;
            if (isset($match[3]) && '/' === $match[3][0]) {
                $result .= $match[0][0];
                continue;
            }
            $rewritten = $this->bindBlock($match[1][0], $match[2][0], $markup, $end);
            if (null === $rewritten) {
                $result .= $match[0][0];
                continue;
            }
            [$delimiter, $tagStart, $tagEnd, $tag] = $rewritten;
            $result .= $delimiter . substr($markup, $end, $tagStart - $end) . $tag;
            $offset = $tagEnd;
        }
        return $result . substr($markup, $offset);
    }

    /**
     * @return array{0:string,1:int,2:int,3:string}|null New delimiter, tag offsets in the source markup, and the new tag.
     */
    private function bindBlock(string $name, string $json, string $markup, int $after): ?array
    {
        $attributes = json_decode($json, false);
        if (!$attributes instanceof \stdClass || self::encode($attributes) !== $json) return null;
        $observed = $this->classStyles[self::authoredClassKey(is_string($attributes->className ?? null) ? $attributes->className : '')] ?? array();
        if (!isset($attributes->style) && array() === $observed) return null;
        if (isset($attributes->style) && !$attributes->style instanceof \stdClass) return null;

        $tag = $this->styledTag($name, $markup, $after);
        if (null === $tag) return null;
        [$tagStart, $tagEnd, $html] = $tag;
        $classes = (string) self::attribute($html, 'class');
        $declarations = self::declarations((string) self::attribute($html, 'style'));
        $tokens = $this->tagTokens($html, $classes . ' ' . (is_string($attributes->className ?? null) ? $attributes->className : ''));
        $supports = self::CLASS_SUPPORTS[$name] ?? array();
        $isLink = in_array($name, self::LINK_BLOCKS, true);
        $addClasses = array();
        $bound = 0;
        $style = $attributes->style ?? new \stdClass();

        $presets = array(
            'text' => array('textColor', 'color', 'color', 'has-text-color', 'has-%s-color'),
            'background' => array('backgroundColor', 'background-color', 'background-color', 'has-background', 'has-%s-background-color'),
        );
        foreach ($presets as $key => [$attribute, $property, $observedProperty, $flagClass, $presetClass]) {
            if (isset($attributes->{$attribute})) continue;
            $literal = $style->color->{$key} ?? null;
            if (is_string($literal) && str_starts_with($literal, 'var:')) continue;
            if (!is_string($literal) && !in_array($key, $supports, true)) continue;
            $added = array($flagClass, sprintf($presetClass, '%s'));
            $slug = $this->presetSlug('color', $literal, $observed[$observedProperty] ?? null);
            if (null === $slug || !$this->safe($property, $tokens, array_map(static fn(string $class): string => sprintf($class, $slug), $added))) continue;
            if (is_string($literal)) {
                if (!self::removeDeclaration($declarations, $property, $literal)) continue;
                unset($style->color->{$key});
            }
            $attributes->{$attribute} = $slug;
            array_push($addClasses, ...array_map(static fn(string $class): string => sprintf($class, $slug), $added));
            ++$bound;
        }

        $typography = array(
            'fontSize' => array('fontSize', 'font-size', 'has-%s-font-size'),
            'fontFamily' => array('fontFamily', 'font-family', 'has-%s-font-family'),
        );
        foreach ($typography as $key => [$attribute, $property, $presetClass]) {
            if ($isLink || isset($attributes->{$attribute})) continue;
            $literal = $style->typography->{$key} ?? null;
            if (is_string($literal) && str_starts_with($literal, 'var:')) continue;
            if (!is_string($literal) && !in_array($key, $supports, true)) continue;
            $slug = $this->presetSlug($property, $literal, $observed[$property] ?? null);
            if (null === $slug || !$this->safe($property, $tokens, array(sprintf($presetClass, $slug)))) continue;
            if (is_string($literal)) {
                if (!self::removeDeclaration($declarations, $property, $literal)) continue;
                unset($style->typography->{$key});
            }
            $attributes->{$attribute} = $slug;
            $addClasses[] = sprintf($presetClass, $slug);
            ++$bound;
        }

        foreach (array('padding', 'margin') as $box) {
            if ($isLink && 'margin' === $box) continue;
            $sides = $style->spacing->{$box} ?? null;
            if ($sides instanceof \stdClass) {
                foreach (self::SPACING_SIDES as $side) {
                    $value = $sides->{$side} ?? null;
                    if (!is_string($value) || str_starts_with($value, 'var:')) continue;
                    $property = $box . '-' . $side;
                    $slug = $this->presetSlug('spacing', $value, 'padding' === $box ? ($observed[$property] ?? null) : null);
                    if (null === $slug || !$this->safe($property, $tokens, array(), true) || !self::replaceDeclaration($declarations, $property, $value, 'var(--wp--preset--spacing--' . $slug . ')')) continue;
                    $sides->{$side} = 'var:preset|spacing|' . $slug;
                    ++$bound;
                }
            } elseif ('padding' === $box && in_array('padding', $supports, true) && !isset($style->spacing->padding)) {
                // Class-driven padding is added side by side, in core's
                // serialization order, as inline preset variables.
                $padding = new \stdClass();
                foreach (self::SPACING_SIDES as $side) {
                    $property = 'padding-' . $side;
                    $slug = $this->presetSlug('spacing', null, $observed[$property] ?? null);
                    if (null === $slug || array() !== array_filter($declarations, static fn(array $declaration): bool => str_starts_with($declaration[0], 'padding')) || !$this->safe($property, $tokens, array(), true, true)) continue;
                    $padding->{$side} = 'var:preset|spacing|' . $slug;
                }
                if (array() !== get_object_vars($padding)) {
                    if (!isset($style->spacing) || !$style->spacing instanceof \stdClass) $style->spacing = new \stdClass();
                    $style->spacing->padding = $padding;
                    foreach (get_object_vars($padding) as $side => $value) {
                        $declarations[] = array('padding-' . $side, 'var(--wp--preset--spacing--' . substr((string) $value, strlen('var:preset|spacing|')) . ')');
                        ++$bound;
                    }
                }
            }
        }
        $gap = $style->spacing->blockGap ?? null;
        if (!$isLink && is_string($gap) && !str_starts_with($gap, 'var:')) {
            $slug = $this->presetSlug('spacing', $gap, null);
            if (null !== $slug && $this->safe('gap', $tokens, array(), true)) {
                $style->spacing->blockGap = 'var:preset|spacing|' . $slug;
                ++$bound;
            }
        }

        if (0 === $bound) return null;
        $this->bound += $bound;
        if (array() !== get_object_vars($style)) $attributes->style = $style;
        if (isset($attributes->style)) self::prune($attributes);
        $newStyle = self::declarationText($declarations);
        $newHtml = self::withAttribute($html, 'style', '' === $newStyle ? null : $newStyle);
        if (array() !== $addClasses) {
            $current = preg_split('/\s+/', trim($classes)) ?: array();
            $newHtml = self::withAttribute($newHtml, 'class', implode(' ', array_values(array_unique(array_filter(array_merge($current, $addClasses), static fn(string $class): bool => '' !== $class)))));
        }
        $delimiter = '<!-- wp:' . $name . (array() === get_object_vars($attributes) ? '' : ' ' . self::encode($attributes)) . ' -->';
        return array($delimiter, $tagStart, $tagEnd, $newHtml);
    }

    /**
     * Binds the values a stylesheet sets that equal a design-system token to
     * the token's preset variable, keeping the literal as the fallback:
     * `color:#93a8ce` becomes
     * `color:var(--wp--preset--color--accent-1,#93a8ce)`.
     *
     * This reaches what block attributes cannot: links inside text,
     * navigation items, interaction states, and author classes whose value
     * the block does not carry. The declaration keeps its selector, its
     * place in the cascade and its `!important`, so the only thing that
     * changes is where the value comes from: it renders the token's value,
     * which is the literal within tolerance, until a site owner edits the
     * token in Global Styles.
     *
     * - A colour (`color`, `background-color`, a lone-colour `background`,
     *   or a custom property whose whole value is a colour) binds within
     *   the palette tolerance. A `var()` in it must resolve the same way as
     *   for a block literal.
     * - A font size binds only when it is the preset's own length, and a
     *   font family only when it is the preset's own stack, so no fallback
     *   or rounding changes what renders.
     */
    public function bindStylesheet(string $css): string
    {
        $transformer = new CssStylesheetTransformer();
        return $transformer->transformStyleRules($css, fn(string $prelude, string $body): string => $prelude . '{' . $this->bindRuleBody($transformer, $body) . '}');
    }

    private function bindRuleBody(CssStylesheetTransformer $transformer, string $body): string
    {
        $result = '';
        foreach ($transformer->splitStyleRuleBody($body) as $part) {
            if (!isset($part['declarations'])) {
                $nested = '' === $part['at_rule'] || in_array($part['at_rule'], array('media', 'supports', 'container', 'layer', 'scope'), true);
                $result .= $part['prelude'] . '{' . ($nested ? $this->bindRuleBody($transformer, $part['body']) : $part['body']) . '}';
                continue;
            }
            $declarations = $part['declarations'];
            $changed = false;
            $rewritten = array();
            foreach (CssValueSplitter::splitTopLevel($declarations, array(';')) as $declaration) {
                $bound = $this->bindDeclaration($declaration);
                $changed = $changed || $bound !== $declaration;
                $rewritten[] = $bound;
            }
            // An untouched run keeps its source bytes.
            $result .= $changed ? implode(';', $rewritten) . (str_ends_with(rtrim($declarations), ';') ? ';' : '') : $declarations;
        }
        return $result;
    }

    private function bindDeclaration(string $declaration): string
    {
        $parts = explode(':', $declaration, 2);
        if (2 !== count($parts)) return $declaration;
        $property = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        $important = '';
        if (1 === preg_match('/\s*!\s*important\s*$/i', $value, $match)) {
            $important = '!important';
            $value = trim(substr($value, 0, -strlen($match[0])));
        }
        if ('' === $value || str_contains($value, '--wp--preset--')) return $declaration;
        $kind = match (true) {
            in_array($property, array('color', 'background-color', 'background'), true), str_starts_with($property, '--') => 'color',
            'font-size' === $property => 'font-size',
            'font-family' === $property => 'font-family',
            default => null,
        };
        if (null === $kind) return $declaration;
        $slug = match ($kind) {
            'color' => $this->comparable('color', $this->resolve($value)) ? $this->system->colorSlug($this->resolve($value)) : null,
            'font-size' => $this->exactFontSizeSlug($value),
            default => $this->exactFontFamilySlug($value),
        };
        if (null === $slug) return $declaration;
        ++$this->bound;
        return $parts[0] . ':var(--wp--preset--' . ('color' === $kind ? 'color' : $kind) . '--' . $slug . ',' . $value . ')' . $important;
    }

    private function exactFontSizeSlug(string $value): ?string
    {
        $px = CssLength::toPx($value, $this->system->rootPx);
        if (null === $px) return null;
        foreach ($this->system->fontSizes as $size) if (abs($size['px'] - $px) < 0.01 && $size['slug'] === $this->system->fontSizeSlug($value)) return $size['slug'];
        return null;
    }

    private function exactFontFamilySlug(string $value): ?string
    {
        $normalize = static fn(string $stack): string => strtolower((string) preg_replace('/[\s"\']+/', '', $stack));
        foreach ($this->system->fontFamilies as $family) if ($normalize($family['fontFamily']) === $normalize($value)) return $family['slug'];
        return null;
    }

    /**
     * The preset slug for a literal value, a class-driven observation, or
     * both. A literal is resolved through root custom properties; when that
     * fails, the value the source rendered for the block's class list stands
     * in for it. When both are known they must agree. Every media-condition
     * variant in the observation must match the same token.
     *
     * @param 'color'|'font-size'|'font-family'|'spacing' $kind
     */
    private function presetSlug(string $kind, ?string $literal, ?string $observation): ?string
    {
        if ('!' === $observation) return null;
        $values = null === $observation || '' === $observation ? array() : explode(';', $observation);
        $resting = $values[0] ?? null;
        if (null !== $literal) {
            $resolved = $this->resolve($literal);
            if ($this->comparable($kind, $resolved)) {
                if (null !== $resting && $this->slug($kind, $resolved) !== $this->slug($kind, $resting)) return null;
                $resting = $resolved;
            }
        }
        if (null === $resting) return null;
        $slug = $this->slug($kind, $resting);
        if (null === $slug) return null;
        foreach (array_slice($values, 1) as $variant) if ($this->slug($kind, $variant) !== $slug) return null;
        return $slug;
    }

    private function comparable(string $kind, string $value): bool
    {
        return match ($kind) {
            'color' => null !== CssColor::parse($value),
            'font-family' => '' !== trim($value) && !str_contains($value, 'var(') && !in_array(strtolower(trim($value)), array('inherit', 'initial', 'unset', 'revert', 'revert-layer'), true),
            default => null !== CssLength::toPx($value),
        };
    }

    private function slug(string $kind, string $value): ?string
    {
        if (!$this->comparable($kind, $value)) return null;
        return match ($kind) {
            'color' => $this->system->colorSlug($value),
            'font-size' => $this->system->fontSizeSlug($value),
            'font-family' => $this->system->fontFamilySlug($value),
            default => $this->system->spacingSlug($value),
        };
    }

    /**
     * The opening tag that carries the block's style support output.
     *
     * @return array{0:int,1:int,2:string}|null
     */
    private function styledTag(string $name, string $markup, int $after): ?array
    {
        if (in_array($name, self::LINK_BLOCKS, true)) {
            // The wrapper div comes first; the styled link must be its first
            // child, before any nested block delimiter.
            if (1 !== preg_match('/\G\s*<div\b[^>]*>\s*(<(?:a|button)\b[^>]*\bwp-block-button__link\b[^>]*>)/', $markup, $match, PREG_OFFSET_CAPTURE, $after)) return null;
            return array($match[1][1], $match[1][1] + strlen($match[1][0]), $match[1][0]);
        }
        if (1 !== preg_match('/\G\s*(<[a-z][a-z0-9-]*\b[^>]*>)/i', $markup, $match, PREG_OFFSET_CAPTURE, $after)) return null;
        return array($match[1][1], $match[1][1] + strlen($match[1][0]), $match[1][0]);
    }

    /**
     * Whether binding a property keeps the cascade outcome.
     *
     * A preset class is `!important`, so any other `!important` rule that can
     * reach the element competes with it; a rule that excludes one of the
     * classes the binding adds (`:not(.has-text-color)`) cannot. Spacing stays
     * inline (as a preset variable), so only selectors on the style text can
     * observe it, and only through a fragment longer than the property name,
     * unless the declaration is new. Interaction-state rules for the property
     * make the resting value one of several, so they block binding too.
     *
     * @param array<string,true> $tokens
     * @param list<string> $addedClasses
     */
    private function safe(string $property, array $tokens, array $addedClasses, bool $staysInline = false, bool $addsDeclaration = false): bool
    {
        $finalClasses = $tokens;
        foreach ($addedClasses as $class) $finalClasses['class:' . $class] = true;
        foreach (self::families($property) as $family) {
            if (isset($this->stateTokens[$family]['*']) || array() !== array_intersect_key($this->stateTokens[$family] ?? array(), $tokens)) return false;
            foreach ($this->styleTextRules[$family] ?? array() as $rule) {
                if (null !== $rule['tokens'] && array() === array_intersect_key(array_flip($rule['tokens']), $tokens)) continue;
                if (array() !== array_intersect_key(array_flip($rule['negated']), $finalClasses)) continue;
                if (!$staysInline || $addsDeclaration || 1 !== preg_match('/^[a-z-]*:?$/', strtolower(trim($rule['fragment'], ' ;')))) return false;
            }
            if ($staysInline) continue;
            foreach ($this->importantRules[$family] ?? array() as $rule) {
                if (array() !== $rule['tokens'] && array() === array_intersect_key(array_flip($rule['tokens']), $tokens)) continue;
                if (array() !== array_intersect_key(array_flip($rule['negated']), $finalClasses)) continue;
                return false;
            }
        }
        return true;
    }

    /**
     * A value with its `var(--name)` references resolved through custom
     * properties that are set once, unconditionally, on the document root
     * (`:root`, `html`, `:host`). Any other definition could give the
     * variable a different value under some block, so a value that reads
     * such a property stays as written, except that a fallback
     * (`var(--tw-text-opacity, 1)`) stands in for a property that every
     * definition sets to that same value: the element renders the fallback
     * whether or not one of them reaches it.
     */
    private function resolve(string $value): string
    {
        for ($depth = 0; $depth < 5 && str_contains($value, 'var('); $depth++) {
            $unresolved = false;
            $value = (string) preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*?)\s*)?\)/', function (array $match) use (&$unresolved): string {
                $resolved = $this->variables[$match[1]] ?? false;
                $fallback = $match[2] ?? '';
                if (false === $resolved && '' !== $fallback && ($this->uniformVariables[$match[1]] ?? null) === $fallback) return $fallback;
                if (false === $resolved) {
                    $unresolved = true;
                    return $match[0];
                }
                return $resolved;
            }, $value);
            if ($unresolved) break;
        }
        return $value;
    }

    /** @param list<string> $ancestors */
    private function indexVariables(string $prelude, string $body, array $ancestors): void
    {
        if (!preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER)) return;
        $selectors = array_map(static fn(string $selector): string => strtolower(trim($selector)), CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude));
        $root = array() === array_diff($selectors, array(':root', 'html', ':host')) && array() === array_filter($ancestors, static fn(string $ancestor): bool => 1 !== preg_match('/^@layer(?:\s|$)/', $ancestor));
        foreach ($matches as $match) {
            $name = (string) $match[1];
            $value = trim((string) $match[2]);
            // Any definition with another value, wherever it applies, could
            // give the variable that value under some block. Repeating the
            // root value (editor mirrors such as `:root :root.editor-styles-wrapper`)
            // changes nothing.
            if (str_contains(strtolower($value), '!important') || (array_key_exists($name, $this->variables) && $this->variables[$name] !== $value)) $this->variables[$name] = false;
            elseif ($root) $this->variables[$name] = $value;
            else $this->scopedVariables[$name][$value] = true;
        }
    }

    private function indexRule(string $prelude, string $body): void
    {
        $nested = str_contains($body, '{') ? (new CssStylesheetTransformer())->splitStyleRuleBody($body) : array(array('declarations' => $body));
        $important = array();
        $declared = array();
        foreach ($nested as $part) {
            if (isset($part['declarations'])) {
                foreach (CssValueSplitter::splitTopLevel((string) $part['declarations'], array(';')) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (2 !== count($parts)) continue;
                    foreach (self::families(strtolower(trim($parts[0]))) as $family) {
                        $declared[$family] = true;
                        if (1 === preg_match('/!\s*important\s*$/i', $parts[1])) $important[$family] = true;
                    }
                }
            } elseif (1 === preg_match(self::STATE_PSEUDO, (string) $part['prelude'])) {
                // A nested interaction state (`&:hover{…}`, Tailwind v4)
                // restyles the parent rule's subject.
                foreach (self::families(self::nestedProperties((string) $part['body'])) as $family) $this->indexState($prelude, $family);
            }
        }
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            if (preg_match_all('/\[\s*style\s*[*^$~|]?=\s*["\']?([^"\'\]]*)/i', $selector, $matches)) {
                // Only a selector reading the subject's own style text is
                // limited to the subject's tokens; one on an ancestor could
                // read any block's.
                $subject = 1 === preg_match('/\[\s*style\b/i', self::subjectCompound($selector)) ? self::subjectTokens($selector) : null;
                $tokens = null === $subject || array() === $subject['tokens'] ? null : $subject['tokens'];
                foreach ($matches[1] as $fragment) foreach (array('color', 'background', 'font-size', 'font-family', 'padding', 'margin') as $family) {
                    if ('' === $fragment || str_contains(strtolower($fragment), $family) || str_contains($family, strtolower(trim($fragment, ' :;')))) {
                        $this->styleTextRules[$family][] = array('tokens' => $tokens, 'negated' => $subject['negated'] ?? array(), 'fragment' => (string) $fragment);
                    }
                }
            }
            if (1 === preg_match(self::STATE_PSEUDO, $selector)) {
                foreach ($declared as $family => $_) $this->indexState($selector, $family);
                continue;
            }
            if (array() === $important) continue;
            $subject = self::subjectTokens($selector);
            if (null === $subject) continue;
            foreach ($important as $family => $_) $this->importantRules[$family][] = $subject;
        }
    }

    private function indexState(string $prelude, string $family): void
    {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            $subject = self::subjectTokens((string) preg_replace(self::STATE_PSEUDO, '', $selector));
            if (null === $subject) continue;
            if (array() === $subject['tokens']) $this->stateTokens[$family]['*'] = true;
            foreach ($subject['tokens'] as $token) $this->stateTokens[$family][$token] = true;
        }
    }

    /** Property names declared anywhere in a nested rule body, through further nesting and conditions. */
    private static function nestedProperties(string $body): string
    {
        $properties = array();
        foreach ((new CssStylesheetTransformer())->splitStyleRuleBody($body) as $part) {
            if (isset($part['declarations'])) {
                foreach (CssValueSplitter::splitTopLevel((string) $part['declarations'], array(';')) as $declaration) {
                    if (str_contains($declaration, ':')) $properties[] = strtolower(trim(explode(':', $declaration, 2)[0]));
                }
            } else {
                $properties[] = self::nestedProperties((string) $part['body']);
            }
        }
        return implode(' ', $properties);
    }

    /** The selector's last compound, after its final combinator. */
    private static function subjectCompound(string $selector): string
    {
        $selector = trim($selector);
        $depth = 0;
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ('\\' === $char) { $i++; continue; }
            if ('(' === $char || '[' === $char) $depth++;
            elseif (')' === $char || ']' === $char) $depth--;
            elseif (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) $start = $i + 1;
        }
        return substr($selector, $start);
    }

    /**
     * Class, id and type tokens in a selector's subject compound, and the
     * classes it excludes with a simple `:not(.class…)`. Tokens are empty when
     * the subject has none (universal or attribute-only); null when the
     * selector targets a pseudo-element rather than the element itself.
     *
     * @return array{tokens:list<string>,negated:list<string>}|null
     */
    private static function subjectTokens(string $selector): ?array
    {
        if (1 === preg_match('/::?(?:before|after|marker|placeholder|selection|first-line|first-letter|backdrop|file-selector-button)\s*$/i', trim($selector))) return null;
        $compound = self::subjectCompound($selector);
        $negated = array();
        $compound = (string) preg_replace_callback('/:not\(\s*((?:\.(?:\\\\.|[\w-])+)+)\s*\)/', static function (array $match) use (&$negated): string {
            if (preg_match_all('/\.((?:\\\\.|[\w-])+)/', $match[1], $classes) && 1 === count($classes[1])) $negated[] = 'class:' . stripslashes($classes[1][0]);
            return '';
        }, $compound);
        $tokens = array();
        if (1 === preg_match('/^([a-z][a-z0-9-]*)/i', $compound, $match)) $tokens[] = 'tag:' . strtolower($match[1]);
        if (preg_match_all('/\.((?:\\\\.|[\w-])+)/', $compound, $matches)) foreach ($matches[1] as $class) $tokens[] = 'class:' . stripslashes($class);
        if (preg_match_all('/#((?:\\\\.|[\w-])+)/', $compound, $matches)) foreach ($matches[1] as $id) $tokens[] = 'id:' . stripslashes($id);
        return array('tokens' => $tokens, 'negated' => $negated);
    }

    /** @return array<string,true> */
    private function tagTokens(string $html, string $classes): array
    {
        $tokens = array();
        if (1 === preg_match('/^<([a-z][a-z0-9-]*)/i', $html, $match)) $tokens['tag:' . strtolower($match[1])] = true;
        foreach (preg_split('/\s+/', trim($classes)) ?: array() as $class) if ('' !== $class) $tokens['class:' . $class] = true;
        $id = self::attribute($html, 'id');
        if (null !== $id && '' !== $id) $tokens['id:' . $id] = true;
        return $tokens;
    }

    /** The author's classes on a block, sorted, without the ones WordPress or the engine add. */
    private static function authoredClassKey(string $className): string
    {
        $classes = array_values(array_unique(array_filter(preg_split('/\s+/', trim($className)) ?: array(), static fn(string $class): bool => '' !== $class && 1 !== preg_match(self::GENERATED_CLASS, $class))));
        sort($classes, SORT_STRING);
        return implode(' ', $classes);
    }

    /**
     * Combine every document's class-list observations. A property keeps its
     * observation only when every element with the class list, on every
     * document, made the same one; otherwise it is `!`. Absence counts as an
     * observation, so a value only some of those elements declare is not
     * bound.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,array<string,string>>
     */
    private static function mergeClassStyles(array $usageBySource): array
    {
        ksort($usageBySource, SORT_STRING);
        $observations = array();
        foreach ($usageBySource as $usage) {
            foreach (is_array($usage['class_styles'] ?? null) ? $usage['class_styles'] : array() as $row) {
                $key = self::authoredClassKey((string) ($row['classes'] ?? ''));
                if ('' === $key) continue;
                foreach (self::CLASS_STYLE_PROPERTIES as $property) {
                    foreach ((array) ($row['properties'][$property] ?? array('')) as $observation) $observations[$key][$property][(string) $observation] = true;
                }
            }
        }
        ksort($observations, SORT_STRING);
        $merged = array();
        foreach ($observations as $key => $properties) {
            foreach ($properties as $property => $values) {
                if (array('' => true) === $values) continue;
                $merged[(string) $key][$property] = 1 === count($values) ? (string) array_key_first($values) : '!';
            }
        }
        return $merged;
    }

    /** @return list<string> */
    private static function families(string $properties): array
    {
        $families = array();
        foreach (preg_split('/\s+/', strtolower(trim($properties))) ?: array() as $property) {
            if ('color' === $property) $families['color'] = true;
            elseif ('background' === $property || str_starts_with($property, 'background-')) $families['background'] = true;
            elseif ('font' === $property) $families['font-size'] = $families['font-family'] = true;
            elseif ('font-size' === $property || 'font-family' === $property) $families[$property] = true;
            else foreach (array('padding', 'margin') as $family) if ($property === $family || str_starts_with($property, $family . '-')) $families[$family] = true;
        }
        return array_keys($families);
    }

    private static function attribute(string $html, string $name): ?string
    {
        if (1 !== preg_match('/\s' . $name . '="([^"]*)"/i', $html, $match)) return null;
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function withAttribute(string $html, string $name, ?string $value): string
    {
        $pattern = '/\s' . $name . '="[^"]*"/i';
        if (null === $value) return (string) preg_replace($pattern, '', $html, 1);
        $encoded = ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        if (1 === preg_match($pattern, $html)) return (string) preg_replace_callback($pattern, static fn(): string => $encoded, $html, 1);
        // Core's block wrappers write `class` before `style`.
        return (string) preg_replace_callback('/\s*\/?>$/', static fn(array $end): string => $encoded . $end[0], $html, 1);
    }

    /** @return list<array{0:string,1:string}> */
    private static function declarations(string $style): array
    {
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel($style, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $declarations[] = array(strtolower(trim($parts[0])), trim($parts[1]));
        }
        return $declarations;
    }

    /** @param list<array{0:string,1:string}> $declarations */
    private static function declarationText(array $declarations): string
    {
        return implode(';', array_map(static fn(array $declaration): string => $declaration[0] . ':' . $declaration[1], $declarations));
    }

    /** @param list<array{0:string,1:string}> $declarations */
    private static function removeDeclaration(array &$declarations, string $property, string $value): bool
    {
        $matches = array_keys(array_filter($declarations, static fn(array $declaration): bool => $declaration[0] === $property));
        if (1 !== count($matches) || $declarations[$matches[0]][1] !== $value) return false;
        array_splice($declarations, $matches[0], 1);
        return true;
    }

    /** @param list<array{0:string,1:string}> $declarations */
    private static function replaceDeclaration(array &$declarations, string $property, string $value, string $replacement): bool
    {
        $matches = array_keys(array_filter($declarations, static fn(array $declaration): bool => $declaration[0] === $property));
        if (1 !== count($matches) || $declarations[$matches[0]][1] !== $value) return false;
        $declarations[$matches[0]][1] = $replacement;
        return true;
    }

    /** Drop style groups the rewrite emptied, as core's serializer would never emit them. */
    private static function prune(\stdClass $attributes): void
    {
        foreach (array('color', 'typography', 'spacing') as $group) {
            if (isset($attributes->style->{$group}) && $attributes->style->{$group} instanceof \stdClass && array() === get_object_vars($attributes->style->{$group})) unset($attributes->style->{$group});
        }
        if (array() === get_object_vars($attributes->style)) unset($attributes->style);
    }

    /** Core's serialize_block_attributes() encoding. */
    private static function encode(\stdClass $attributes): string
    {
        $json = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) return '';
        return strtr($json, array('\\\\' => '\\u005c', '--' => '\\u002d\\u002d', '<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022'));
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * Rewrites literal block style values that match a design-system token into
 * WordPress preset references, so editing the token in Global Styles restyles
 * the block.
 *
 * - `style.color.text` becomes `textColor` (`has-{slug}-color`).
 * - `style.color.background` becomes `backgroundColor`
 *   (`has-{slug}-background-color`).
 * - `style.typography.fontSize` becomes `fontSize` (`has-{slug}-font-size`).
 * - `style.spacing.padding|margin|blockGap` values become
 *   `var:preset|spacing|{slug}`.
 *
 * The inline declaration WordPress generated for the literal is removed (or
 * rewritten to the preset variable for spacing) in the same block, so the
 * saved markup is what core's save function produces for the new attributes.
 *
 * Each block is rewritten on its own, from its delimiter and its own opening
 * tag, so the rewrite gives the same result on a whole page and on any
 * fragment that contains the block. It is idempotent: a bound value is no
 * longer a literal.
 *
 * A value is left alone when binding could change what renders:
 *
 * - the inline declaration is not exactly the attribute value WordPress
 *   serialized (the markup was not produced from these attributes);
 * - re-encoding the delimiter JSON would not reproduce it byte for byte;
 * - a stylesheet declares the property `!important` for a selector whose
 *   subject this block could match. A preset class is `!important`, an inline
 *   style is not, so such a rule would now compete differently;
 * - a stylesheet selects on the inline style text (`[style*=…]`) for that
 *   property.
 */
final class PresetBinding
{
    /** Blocks whose colour, font-size and spacing support serializes on the wrapper. */
    private const WRAPPER_BLOCKS = array('paragraph', 'heading', 'group', 'columns', 'column', 'list', 'list-item', 'quote', 'buttons');

    /** core/button serializes colour and padding on its inner link. */
    private const LINK_BLOCKS = array('button');

    private const SPACING_SIDES = array('top', 'right', 'bottom', 'left');

    /** @var array<string,true> Families some selector reads from the inline style text. */
    private array $styleTextRisky = array();
    /** @var array<string,true> Families an `!important` rule sets on any element. */
    private array $globallyRisky = array();
    /** @var array<string,array<string,true>> */
    private array $riskyTokens = array();
    private int $bound = 0;
    /** @var array<string,string|false> Root custom properties; false when a value is scoped or conditional. */
    private array $variables = array();
    /** @var array<string,array<string,true>> Values custom properties take outside the root. */
    private array $scopedVariables = array();

    /** @param array<int,array<string,mixed>> $assets */
    public function __construct(private readonly DesignSystem $system, array $assets)
    {
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], function (string $prelude, string $body, array $ancestors = array()): void {
                $this->indexRule($prelude, $body);
                $this->indexVariables($prelude, $body, $ancestors);
            });
        }
    }

    /** Number of literal values bound to presets so far. */
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
        if (!str_contains($json, '"style"')) return null;
        $attributes = json_decode($json, false);
        if (!$attributes instanceof \stdClass || !isset($attributes->style) || !$attributes->style instanceof \stdClass) return null;
        if (self::encode($attributes) !== $json) return null;

        $tag = $this->styledTag($name, $markup, $after);
        if (null === $tag) return null;
        [$tagStart, $tagEnd, $html] = $tag;
        $classes = self::attribute($html, 'class');
        $style = self::attribute($html, 'style');
        if (null === $style) return null;
        $tokens = $this->tagTokens($html, (string) $classes);
        $declarations = self::declarations($style);
        $addClasses = array();
        $changed = false;
        $isLink = in_array($name, self::LINK_BLOCKS, true);

        foreach (array('text' => array('textColor', 'color', '%s-color'), 'background' => array('backgroundColor', 'background-color', '%s-background-color')) as $key => [$attribute, $property, $class]) {
            $value = $attributes->style->color->{$key} ?? null;
            if (!is_string($value) || isset($attributes->{$attribute}) || str_starts_with($value, 'var:')) continue;
            $slug = $this->system->colorSlug($this->resolve($value));
            if (null === $slug || !$this->safe($property, $tokens) || !self::removeDeclaration($declarations, $property, $value)) continue;
            unset($attributes->style->color->{$key});
            $attributes->{$attribute} = $slug;
            $addClasses[] = 'has-' . sprintf($class, $slug);
            $changed = true;
        }

        $fontSize = $attributes->style->typography->fontSize ?? null;
        if (!$isLink && is_string($fontSize) && !isset($attributes->fontSize)) {
            $slug = $this->system->fontSizeSlug($this->resolve($fontSize));
            if (null !== $slug && $this->safe('font-size', $tokens) && self::removeDeclaration($declarations, 'font-size', $fontSize)) {
                unset($attributes->style->typography->fontSize);
                $attributes->fontSize = $slug;
                $addClasses[] = 'has-' . $slug . '-font-size';
                $changed = true;
            }
        }

        foreach (array('padding', 'margin') as $box) {
            if ($isLink && 'margin' === $box) continue;
            $sides = $attributes->style->spacing->{$box} ?? null;
            if (!$sides instanceof \stdClass) continue;
            foreach (self::SPACING_SIDES as $side) {
                $value = $sides->{$side} ?? null;
                if (!is_string($value) || str_starts_with($value, 'var:')) continue;
                $slug = $this->system->spacingSlug($this->resolve($value));
                $property = $box . '-' . $side;
                if (null === $slug || !$this->safe($property, $tokens, true) || !self::replaceDeclaration($declarations, $property, $value, 'var(--wp--preset--spacing--' . $slug . ')')) continue;
                $sides->{$side} = 'var:preset|spacing|' . $slug;
                ++$this->bound;
                $changed = true;
            }
        }
        $gap = $attributes->style->spacing->blockGap ?? null;
        if (!$isLink && is_string($gap) && !str_starts_with($gap, 'var:')) {
            $slug = $this->system->spacingSlug($this->resolve($gap));
            if (null !== $slug && $this->safe('gap', $tokens, true)) {
                $attributes->style->spacing->blockGap = 'var:preset|spacing|' . $slug;
                ++$this->bound;
                $changed = true;
            }
        }

        if (!$changed) return null;
        $this->bound += count($addClasses);
        self::prune($attributes);
        $newStyle = self::declarationText($declarations);
        $newHtml = self::withAttribute($html, 'style', '' === $newStyle ? null : $newStyle);
        if (array() !== $addClasses) {
            $current = preg_split('/\s+/', trim((string) $classes)) ?: array();
            $newHtml = self::withAttribute($newHtml, 'class', implode(' ', array_values(array_unique(array_filter(array_merge($current, $addClasses), static fn(string $class): bool => '' !== $class)))));
        }
        $delimiter = '<!-- wp:' . $name . (array() === get_object_vars($attributes) ? '' : ' ' . self::encode($attributes)) . ' -->';
        return array($delimiter, $tagStart, $tagEnd, $newHtml);
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
     * Whether moving a value from the inline style to an `!important` preset
     * class keeps the cascade outcome. Spacing stays inline (as a preset
     * variable), so only selectors on the style text can observe it.
     *
     * @param array<string,true> $tokens
     */
    private function safe(string $property, array $tokens, bool $staysInline = false): bool
    {
        foreach (self::families($property) as $family) {
            if (isset($this->styleTextRisky[$family])) return false;
            if ($staysInline) continue;
            if (isset($this->globallyRisky[$family])) return false;
            foreach ($tokens as $token => $_) if (isset($this->riskyTokens[$family][$token])) return false;
        }
        return true;
    }

    /**
     * A `var(--name)` value resolved through custom properties that are set
     * once, unconditionally, on the document root. Any other definition could
     * give the variable a different value under some block, so such a
     * reference stays as written.
     */
    private function resolve(string $value): string
    {
        foreach ($this->scopedVariables as $name => $values) {
            if (is_string($this->variables[$name] ?? null) && array(array($this->variables[$name] => true)) !== array($values)) $this->variables[$name] = false;
            if (!array_key_exists($name, $this->variables)) $this->variables[$name] = false;
        }
        $this->scopedVariables = array();
        for ($depth = 0; $depth < 5; $depth++) {
            if (1 !== preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/', trim($value), $match)) return $value;
            $resolved = $this->variables[$match[1]] ?? false;
            if (false === $resolved) return $value;
            $value = $resolved;
        }
        return $value;
    }

    /** @param list<string> $ancestors */
    private function indexVariables(string $prelude, string $body, array $ancestors): void
    {
        if (!preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER)) return;
        $root = in_array(strtolower(trim($prelude)), array(':root', 'html'), true) && array() === array_filter($ancestors, static fn(string $ancestor): bool => 1 !== preg_match('/^@layer(?:\s|$)/', $ancestor));
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
        $important = array();
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $property = strtolower(trim($parts[0]));
            if (1 === preg_match('/!\s*important\s*$/i', $parts[1])) foreach (self::families($property) as $family) $important[$family] = true;
        }
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            if (preg_match_all('/\[\s*style\s*[*^$~|]?=\s*["\']?([^"\'\]]*)/i', $selector, $matches)) {
                foreach ($matches[1] as $fragment) foreach (array('color', 'background', 'font-size', 'padding', 'margin') as $family) {
                    if ('' === $fragment || str_contains(strtolower($fragment), $family) || str_contains($family, strtolower(trim($fragment, ' :;')))) $this->styleTextRisky[$family] = true;
                }
            }
            if (array() === $important) continue;
            $subject = self::subjectTokens($selector);
            if (null === $subject) continue;
            foreach ($important as $family => $_) {
                if (array() === $subject) $this->globallyRisky[$family] = true;
                foreach ($subject as $token) $this->riskyTokens[$family][$token] = true;
            }
        }
    }

    /**
     * Class, id and type tokens in a selector's subject compound. Empty when
     * the subject has none (universal or attribute-only); null when the
     * selector targets a pseudo-element rather than the element itself.
     *
     * @return list<string>|null
     */
    private static function subjectTokens(string $selector): ?array
    {
        $selector = trim($selector);
        if (1 === preg_match('/::?(?:before|after|marker|placeholder|selection|first-line|first-letter|backdrop|file-selector-button)\s*$/i', $selector)) return null;
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
        $compound = substr($selector, $start);
        $tokens = array();
        if (1 === preg_match('/^([a-z][a-z0-9-]*)/i', $compound, $match)) $tokens[] = 'tag:' . strtolower($match[1]);
        if (preg_match_all('/\.((?:\\\\.|[\w-])+)/', $compound, $matches)) foreach ($matches[1] as $class) $tokens[] = 'class:' . stripslashes($class);
        if (preg_match_all('/#((?:\\\\.|[\w-])+)/', $compound, $matches)) foreach ($matches[1] as $id) $tokens[] = 'id:' . stripslashes($id);
        return $tokens;
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

    /** @return list<string> */
    private static function families(string $property): array
    {
        $property = strtolower($property);
        if ('color' === $property) return array('color');
        if ('background' === $property || str_starts_with($property, 'background-')) return array('background');
        if ('font' === $property || 'font-size' === $property) return array('font-size');
        foreach (array('padding', 'margin') as $family) if ($property === $family || str_starts_with($property, $family . '-')) return array($family);
        return array();
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
        return (string) preg_replace('/^(<[a-z][a-z0-9-]*)/i', '$1' . $encoded, $html, 1);
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

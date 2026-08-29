<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use DOMDocument;
use DOMElement;

/**
 * Render-free static CSS cascade resolver.
 *
 * Resolves the *effective* declared value of CSS properties for an element by
 * statically matching the document's author stylesheets (every <style> block,
 * plus any explicitly supplied CSS such as a linked stylesheet inlined by the
 * caller) and the element's inline style attribute, then applying CSS
 * inheritance for inheritable properties from the nearest declaring ancestor.
 *
 * This is a deterministic, browser-free approximation of getComputedStyle for
 * the subset of author-declared properties that visual parity cares about: same
 * input HTML+CSS always yields byte-identical output. It does NOT compute used
 * layout geometry (box sizes, resolved lengths) — only the cascaded *declared*
 * values — which is exactly the contract a static parity signal needs: "does the
 * same effective styling apply to the same content".
 *
 * Mirrors the proven selector engine in {@see TypographyVisualProbe} and adds
 * deterministic specificity-then-source-order cascade ordering so the highest
 * specificity declaration wins, with inline styles overriding all author rules.
 */
final class StaticCssCascade
{
    /**
     * Viewport width @media conditions are resolved against, matching the
     * desktop reference the transformer itself uses for responsive decisions.
     */
    private const REFERENCE_VIEWPORT_WIDTH_PX = 1440;

    /** Root font size used to convert rem/em media-query widths to pixels. */
    private const ROOT_FONT_SIZE_PX = 16;

    /**
     * @var array<int, array{selector: string, declarations: array<string, string>, specificity: int, order: int}>
     */
    private array $rules;

    public function __construct(DOMDocument $document, string $extraCss = '')
    {
        $this->rules = $this->buildRules($document, $extraCss);
    }

    /**
     * Resolve the effective declared style for the requested properties.
     *
     * @param array<int, string> $properties  Properties to resolve (lowercase).
     * @param array<int, string> $inheritable Subset of $properties that inherit.
     * @return array<string, string> property => declared value (ksorted)
     */
    public function resolve(DOMElement $element, array $properties, array $inheritable): array
    {
        $style = $this->cascadedStyle($element);

        foreach ( $inheritable as $field ) {
            if ( isset($style[$field]) && '' !== trim($style[$field]) ) {
                continue;
            }
            for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                $ancestor = $this->cascadedStyle($node);
                if ( isset($ancestor[$field]) && '' !== trim($ancestor[$field]) ) {
                    $style[$field] = $ancestor[$field];
                    break;
                }
            }
        }

        $customProperties = $this->customProperties($element);
        foreach ($style as $property => $value) {
            if (! str_starts_with($property, '--')) {
                $style[$property] = $this->resolveVariables($value, $customProperties);
            }
        }
        $style = array_intersect_key($style, array_flip($properties));
        ksort($style);

        return $style;
    }

    /** @return array<string, string> */
    private function customProperties(DOMElement $element): array
    {
        $nodes = array();
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            array_unshift($nodes, $node);
        }
        $properties = array();
        foreach ($nodes as $node) {
            foreach ($this->cascadedStyle($node) as $name => $value) {
                if (str_starts_with($name, '--')) {
                    $properties[$name] = $value;
                }
            }
        }
        return $properties;
    }

    /** Resolve local/inherited variables and one-level fallback chains deterministically. */
    private function resolveVariables(string $value, array $properties): string
    {
        for ($depth = 0; $depth < 12 && str_contains($value, 'var('); ++$depth) {
            $resolved = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]+))?\)/', static function (array $matches) use ($properties): string {
                $name = $matches[1];
                return isset($properties[$name]) ? $properties[$name] : trim((string) ($matches[2] ?? $matches[0]));
            }, $value);
            if ($resolved === $value || null === $resolved) {
                break;
            }
            $value = $resolved;
        }
        return $value;
    }

    /**
     * Resolve matching declarations using importance, specificity, source order,
     * and inline-origin precedence.
     *
     * @return array<string, string>
     */
    private function cascadedStyle(DOMElement $element): array
    {
        $matched = array();
        foreach ( $this->rules as $rule ) {
            if ( $this->matchesSimpleSelector($element, $rule['selector']) ) {
                $matched[] = $rule;
            }
        }

        $resolved = array();
        foreach ( $matched as $rule ) {
            $this->applyDeclarations($resolved, $rule['declarations'], $rule['specificity'], $rule['order'], false);
        }

        if ( $element->hasAttribute('style') ) {
            $this->applyDeclarations($resolved, $this->declarations($element->getAttribute('style')), 10000, PHP_INT_MAX, true);
        }

        return array_map(static fn (array $entry): string => $entry['value'], $resolved);
    }

    /**
     * @param array<string, array{value: string, important: bool, specificity: int, order: int, inline: bool}> $resolved
     * @param array<string, string> $declarations
     */
    private function applyDeclarations(array &$resolved, array $declarations, int $specificity, int $order, bool $inline): void
    {
        foreach ($declarations as $name => $rawValue) {
            $important = 1 === preg_match('/\s*!important\s*$/i', $rawValue);
            $value = preg_replace('/\s*!important\s*$/i', '', $rawValue) ?? $rawValue;
            $current = $resolved[$name] ?? null;
            if (is_array($current)
                && (int) $current['important'] > (int) $important) {
                continue;
            }
            if (is_array($current)
                && (bool) $current['important'] === $important
                && ($current['specificity'] > $specificity
                    || ($current['specificity'] === $specificity && $current['order'] > $order)
                    || ($current['specificity'] === $specificity && $current['order'] === $order && $current['inline'] && ! $inline))) {
                continue;
            }
            $resolved[$name] = array(
                'value' => $value,
                'important' => $important,
                'specificity' => $specificity,
                'order' => $order,
                'inline' => $inline,
            );
        }
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>, specificity: int, order: int}>
     */
    private function buildRules(DOMDocument $document, string $extraCss): array
    {
        $rules = array();
        $order = 0;

        $cssBlocks = array();
        if ( '' !== trim($extraCss) ) {
            $cssBlocks[] = $extraCss;
        }
        foreach ( $document->getElementsByTagName('style') as $style ) {
            $cssBlocks[] = (string) $style->textContent;
        }

        foreach ( $cssBlocks as $css ) {
            // Comments must go before anything reads the rule grammar. The flat
            // `selector { declarations }` scan treats everything between the
            // previous `}` and the next `{` as the selector, so a section header
            // comment is glued onto the selector of the rule that follows it and
            // that rule then matches nothing. It is silent, and it lands on the
            // first rule after every comment — which in a hand-authored
            // stylesheet is typically the structural one (`:root`, `*`, `body`,
            // a layout container, a landmark).
            $css = $this->stripComments($css);
            $css = $this->stripAtRuleBlocks($css);
            if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $matches as $match ) {
                $declarations = $this->declarations((string) $match[2]);
                if ( array() === $declarations ) {
                    continue;
                }
                // Parenthesis-aware: a comma inside functional notation is not
                // a selector-list separator.
                foreach ( CssStylesheetTransformer::splitSelectorList((string) $match[1]) ?? array() as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector ) {
                        continue;
                    }
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $declarations,
                        'specificity' => $this->specificity($selector),
                        'order' => $order++,
                    );
                }
            }
        }

        return $rules;
    }

    /** Remove `/* … *&#47;` comments so they cannot be absorbed into a selector. */
    private function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    /**
     * Remove at-rule prelude tokens (@media/@supports/@font-face headers and
     * @keyframes blocks) that would otherwise corrupt the flat rule grammar.
     *
     * @media blocks are resolved against {@see REFERENCE_VIEWPORT_WIDTH_PX}
     * rather than flattened unconditionally. Flattening every block makes a
     * `@media (max-width: 1080px) { .main-nav { display: none } }` rule declare
     * `display: none` on the desktop nav in base state, which is the opposite of
     * what the stylesheet says at the reference width. @supports and @layer are
     * still unwrapped: they carry no viewport condition, so their rules do
     * declare effective style. @keyframes interiors are dropped because their
     * "selectors" (0%, to, from) are not element selectors.
     */
    private function stripAtRuleBlocks(string $css): string
    {
        // Drop @keyframes blocks (including nested braces) entirely.
        $css = preg_replace('/@(?:-webkit-|-moz-|-o-)?keyframes\b[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '', $css) ?? $css;
        // Drop @font-face / @import / @charset prelude+block which carry no element rules.
        $css = preg_replace('/@font-face\b[^{]*\{[^{}]*\}/i', '', $css) ?? $css;
        $css = preg_replace('/@(?:import|charset)\b[^;]*;/i', '', $css) ?? $css;
        // Resolve @media against the reference viewport, keeping only the blocks
        // that apply there.
        $css = $this->resolveMediaBlocks($css);
        // Unwrap @supports/@layer wrappers, keeping their inner rules.
        $css = preg_replace('/@(?:supports|layer)\b[^{]*\{/i', '', $css) ?? $css;

        return $css;
    }

    /**
     * Inline the contents of every @media block that applies at the reference
     * viewport and drop the rest, brace-balanced so nested rules survive intact.
     */
    private function resolveMediaBlocks(string $css): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($css);

        while ( $offset < $length ) {
            if ( ! preg_match('/@media\b/i', $css, $match, PREG_OFFSET_CAPTURE, $offset) ) {
                $out .= substr($css, $offset);
                break;
            }

            $start = (int) $match[0][1];
            $out  .= substr($css, $offset, $start - $offset);

            $bracePos = strpos($css, '{', $start);
            if ( false === $bracePos ) {
                // Truncated at-rule with no block: nothing further to resolve.
                break;
            }

            $condition = trim(substr($css, $start + strlen('@media'), $bracePos - $start - strlen('@media')));

            $depth = 0;
            $end   = $bracePos;
            for ( $index = $bracePos; $index < $length; $index++ ) {
                if ( '{' === $css[$index] ) {
                    $depth++;
                    continue;
                }
                if ( '}' === $css[$index] ) {
                    $depth--;
                    if ( 0 === $depth ) {
                        $end = $index;
                        break;
                    }
                }
            }

            if ( 0 !== $depth ) {
                // Unbalanced block: keep the remainder verbatim rather than guessing.
                $out .= substr($css, $bracePos + 1);
                break;
            }

            if ( $this->mediaConditionApplies($condition) ) {
                $out .= "\n" . substr($css, $bracePos + 1, $end - $bracePos - 1) . "\n";
            }

            $offset = $end + 1;
        }

        return $out;
    }

    /**
     * Does a media condition hold at the reference viewport?
     *
     * Width features are evaluated numerically. Non-visual media types are
     * rejected. Anything else this resolver does not model (orientation,
     * prefers-*, hover) is kept, so an unmodelled condition degrades to the
     * previous flattening behaviour rather than silently deleting author style.
     */
    private function mediaConditionApplies(string $condition): bool
    {
        $condition = strtolower(trim($condition));

        if ( '' === $condition ) {
            return true;
        }

        if ( preg_match('/\b(?:print|speech|aural|braille|embossed|tty)\b/', $condition) ) {
            return false;
        }

        foreach ( array( 'min' => '>=', 'max' => '<=' ) as $bound => $comparison ) {
            if ( ! preg_match_all('/\(\s*' . $bound . '-width\s*:\s*([0-9.]+)\s*(px|rem|em)?\s*\)/', $condition, $matches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $matches as $widthMatch ) {
                $value = (float) $widthMatch[1];
                if ( in_array($widthMatch[2] ?? 'px', array( 'rem', 'em' ), true) ) {
                    $value *= self::ROOT_FONT_SIZE_PX;
                }
                $holds = '>=' === $comparison
                    ? self::REFERENCE_VIEWPORT_WIDTH_PX >= $value
                    : self::REFERENCE_VIEWPORT_WIDTH_PX <= $value;
                if ( ! $holds ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Deterministic specificity heuristic: 100 per #id, 10 per .class/[attr]/
     * pseudo-class, 1 per element/pseudo-element. Inline styles are applied
     * separately and always win.
     */
    private function specificity(string $selector): int
    {
        $selector = trim(preg_replace('/::?(hover|focus|active|visited|before|after)\b[^ ]*/', '', $selector) ?? $selector);
        if ( preg_match('/:(?:is|where|not)\s*\(/i', $selector) ) {
            $parsed = CssSelectorMatcher::parse($selector);
            if ( $parsed['supported'] ) {
                return $this->parsedSpecificity($parsed['compounds']);
            }
        }
        $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $selector);
        $classes = preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]/', $selector);
        $bare = preg_replace('/[#.][A-Za-z0-9_-]+|\[[^\]]+\]|[>+~]/', ' ', $selector) ?? $selector;
        $elements = preg_match_all('/[A-Za-z][A-Za-z0-9_-]*/', $bare);

        return ( (int) $ids * 100 ) + ( (int) $classes * 10 ) + (int) $elements;
    }

    /**
     * Specificity for selectors parsed by the production matcher.
     *
     * CssSelectorMatcher records which simple selectors came from :where() so
     * rewriting can preserve their zero specificity. Account for that metadata
     * here rather than maintaining a second functional-selector parser.
     *
     * @param list<array<string, mixed>> $compounds
     */
    private function parsedSpecificity(array $compounds): int
    {
        $specificity = 0;
        foreach ( $compounds as $compound ) {
            $zero = $compound['zero_specificity'] ?? array();
            $specificity += 100 * (count($compound['ids']) - (int) ($zero['ids'] ?? 0));
            $specificity += 10 * (
                count($compound['classes']) - (int) ($zero['classes'] ?? 0)
                + count($compound['attributes']) - (int) ($zero['attributes'] ?? 0)
                + (int) (null !== $compound['nth_child'])
                + (int) $compound['first_child']
                + (int) $compound['last_child']
            );
            $specificity += (int) (null !== $compound['type']) - (int) ($zero['types'] ?? 0);
            foreach ( $compound['not'] as $negated ) {
                $specificity += $this->parsedSpecificity(array( $negated ));
            }
        }

        return $specificity;
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $style): array
    {
        $declarations = array();
        foreach ( explode(';', $style) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            if ( '' !== $name && '' !== $value ) {
                $declarations[$name] = preg_replace('/\s+/', ' ', $value) ?? $value;
            }
        }

        return $declarations;
    }

    private function matchesSimpleSelector(DOMElement $element, string $selector): bool
    {
        $selector = trim($selector);

        // A rule gated on interaction state, or one targeting a pseudo-element,
        // does not style the element in its resting state. Rewriting it into a
        // base-state rule (by deleting the pseudo-class and matching what is
        // left) lets `a:hover { color: red }` outrank the real `a { color: blue }`
        // on source order, so the probe reports the hover colour as the base
        // colour and every such link becomes a false parity finding.
        if ( $this->isNonBaseStateSelector($selector) ) {
            return false;
        }

        // `:root` is the document element. Without this it falls through every
        // branch below and returns false, so `:root { --token: … }` never matches
        // and no custom property is ever collected — leaving every `var(--token)`
        // reference unresolved on the source side while the candidate side
        // carries values the transformer already resolved.
        if ( ':root' === strtolower($selector) ) {
            return null !== $element->ownerDocument && $element === $element->ownerDocument->documentElement;
        }

        // `:is()`, `:where()` and `:not()` are the grammar the transformer's own
        // author-stylesheet projection emits to preserve author specificity, e.g.
        // `.footer-col ul :where(.be-source-li-…):not(be-specificity-…) a`. Without
        // support here the probe cannot match the candidate's own generated rules,
        // so it reports the inherited value and blames the transformer for a
        // declaration it carried correctly.
        if ( preg_match('/:(?:is|where|not)\s*\(/i', $selector) ) {
            $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));
            return $match['supported'] && $match['matches'];
        }

        if ( '' === $selector || str_contains($selector, '+') || str_contains($selector, '~') || str_contains($selector, '[') ) {
            return false;
        }

        if ( str_contains($selector, '>') ) {
            return $this->matchesChildSelector($element, $selector);
        }

        if ( str_contains($selector, ' ') ) {
            return $this->matchesDescendantSelector($element, $selector);
        }

        if ( '*' === $selector ) {
            return true;
        }

        if ( preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $match) ) {
            return $element->hasAttribute('id') && $element->getAttribute('id') === $match[1];
        }

        if ( preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $match) ) {
            return in_array($match[1], $this->tokens($element->hasAttribute('class') ? $element->getAttribute('class') : ''), true);
        }

        if ( preg_match('/^([A-Za-z0-9_-]+)(\.[A-Za-z0-9_-]+)+$/', $selector) ) {
            $parts = explode('.', $selector);
            $tag = array_shift($parts);
            if ( strtolower((string) $tag) !== strtolower($element->tagName) ) {
                return false;
            }
            $classes = $this->tokens($element->hasAttribute('class') ? $element->getAttribute('class') : '');
            foreach ( $parts as $class ) {
                if ( ! in_array($class, $classes, true) ) {
                    return false;
                }
            }

            return true;
        }

        if ( ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $selector) ) {
            return false;
        }

        return strtolower($selector) === strtolower($element->tagName);
    }

    /**
     * Does this selector depend on interaction state or target a pseudo-element?
     *
     * Structural pseudo-classes (`:first-child`, `:nth-of-type()`, `:not()`) are
     * deliberately absent: they describe the resting document, so they belong in
     * base state. They are unsupported by the matcher for other reasons and fail
     * closed further down rather than being silently rewritten.
     */
    private function isNonBaseStateSelector(string $selector): bool
    {
        return 1 === preg_match(
            '/::?(?:hover|focus|focus-within|focus-visible|focus-visible-within|active|visited|target|any-link|checked|indeterminate|placeholder-shown|user-invalid)\b/i',
            $selector
        ) || 1 === preg_match(
            '/::(?:before|after|placeholder|selection|marker|backdrop|first-line|first-letter)\b/i',
            $selector
        ) || 1 === preg_match(
            // Single-colon legacy pseudo-element syntax (`:before`, `:after`).
            '/:(?:before|after|first-line|first-letter)\b/i',
            $selector
        );
    }

    private function matchesChildSelector(DOMElement $element, string $selector): bool
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/', trim($selector)) ?: array())));
        if ( count($parts) < 2 || ! $this->matchesSimpleSelector($element, array_pop($parts)) ) {
            return false;
        }

        $current = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        for ( $index = count($parts) - 1; $index >= 0; --$index ) {
            if ( ! $current instanceof DOMElement || ! $this->matchesSimpleSelector($current, $parts[$index]) ) {
                return false;
            }
            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return true;
    }

    private function matchesDescendantSelector(DOMElement $element, string $selector): bool
    {
        $parts = preg_split('/\s+/', trim($selector)) ?: array();
        if ( array() === $parts || ! $this->matchesSimpleSelector($element, array_pop($parts)) ) {
            return false;
        }

        $current = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        for ( $index = count($parts) - 1; $index >= 0; --$index ) {
            $matched = false;
            for ( $node = $current; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                if ( $this->matchesSimpleSelector($node, $parts[$index]) ) {
                    $matched = true;
                    $current = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: array(), static fn (string $token): bool => '' !== $token));
    }
}

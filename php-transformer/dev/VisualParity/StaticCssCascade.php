<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\Css\AuthorCascadeLayerOrder;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
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
     * @var array<int, array{selector: string, declarations: array<string, string>, specificity: int, order: int, layer: int|null}>
     */
    private array $rules;

    /**
     * Cascade-layer position by top-level layer name, in registration order.
     *
     * A layer's precedence comes from where its name is first registered, not
     * from where its rules appear, so this is built once per stylesheet and
     * shared by every rule inside it. Unlayered rules carry `null`, which
     * {@see CssCascade::compareLayers()} already ranks above every layer for
     * normal declarations and below every layer for `!important` ones.
     *
     * @var array<string, int>
     */
    private array $layerPositions = array();

    /** Distinguishes anonymous `@layer {}` blocks, which share no name. */
    private int $anonymousLayerCount = 0;

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
            $this->applyDeclarations($resolved, $rule['declarations'], $rule['specificity'], $rule['order'], false, $rule['layer']);
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
    private function applyDeclarations(array &$resolved, array $declarations, int $specificity, int $order, bool $inline, ?int $layer = null): void
    {
        foreach ($declarations as $name => $rawValue) {
            $important = 1 === preg_match('/\s*!important\s*$/i', $rawValue);
            $value = preg_replace('/\s*!important\s*$/i', '', $rawValue) ?? $rawValue;
            CssCascade::apply($resolved, $name, array(
                'value' => $value,
                'important' => $important,
                'specificity' => $specificity,
                'order' => $order,
                'inline' => $inline,
                'layer' => $layer,
            ));
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
            $this->registerLayerOrder($css);
            // Comments must go before anything reads the rule grammar. The flat
            // `selector { declarations }` scan treats everything between the
            // previous `}` and the next `{` as the selector, so a section header
            // comment is glued onto the selector of the rule that follows it and
            // that rule then matches nothing. It is silent, and it lands on the
            // first rule after every comment — which in a hand-authored
            // stylesheet is typically the structural one (`:root`, `*`, `body`,
            // a layout container, a landmark).
            $this->collectRules($this->stripComments($css), null, $rules, $order);
        }

        return $rules;
    }

    /**
     * Walk one stylesheet, carrying the cascade layer each rule sits in.
     *
     * At-rules were previously removed textually — `@media` blocks that applied
     * at the reference viewport were inlined and `@layer`/`@supports` wrappers
     * were deleted outright — and the flat remainder was scanned for
     * `selector { declarations }`. Deleting the `@layer` wrapper discards the
     * author's own precedence: a declaration in a later layer loses to an
     * earlier layer whenever the earlier one is more specific, which is the
     * inversion of what the author wrote and the second failure axis in #1898.
     * It also made unlayered engine CSS indistinguishable from layered author
     * CSS, so the probe could not see the escalation in #1854 or #1879 at all.
     *
     * Walking instead of stripping keeps the nesting, which is also what lets a
     * `@media` inside a `@layer` (and the reverse) resolve correctly.
     *
     * @param array<int, array<string, mixed>> $rules
     */
    private function collectRules(string $css, ?string $layer, array &$rules, int &$order): void
    {
        foreach ( $this->topLevelItems($css) as $item ) {
            $prelude = $item['prelude'];
            $body = $item['body'];

            if ( str_starts_with($prelude, '@') ) {
                $this->collectAtRule($prelude, $body, $layer, $rules, $order);
                continue;
            }

            if ( null === $body ) {
                continue;
            }

            // Nested rules need a `&` resolution the matcher does not model, so
            // read only this rule's own declarations and leave the nesting
            // unmatched rather than attributing a child's declarations to it.
            $declarations = $this->declarations($this->withoutNestedBlocks($body));
            if ( array() === $declarations ) {
                continue;
            }

            // Parenthesis-aware: a comma inside functional notation is not
            // a selector-list separator.
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $selector = trim($selector);
                if ( '' === $selector ) {
                    continue;
                }
                $rules[] = array(
                    'selector' => $selector,
                    'declarations' => $declarations,
                    'specificity' => $this->specificity($selector),
                    'order' => $order++,
                    'layer' => $this->layerPosition($layer),
                );
            }
        }
    }

    /**
     * Descend into an at-rule, or drop it when it declares no element rules.
     *
     * @param array<int, array<string, mixed>> $rules
     */
    private function collectAtRule(string $prelude, ?string $body, ?string $layer, array &$rules, int &$order): void
    {
        if ( 1 === preg_match('/^@layer\b(.*)$/is', $prelude, $match) ) {
            // Statement form (`@layer base, utilities;`) only registers order,
            // which registerLayerOrder() has already read off the stylesheet.
            if ( null === $body ) {
                return;
            }
            $name = trim($match[1]);
            if ( '' === $name ) {
                // An anonymous layer holds a position no later rule can name, so
                // it must not merge with any other anonymous block.
                $name = "\0anonymous-" . ( ++$this->anonymousLayerCount );
            }
            // A nested layer inherits its top-level ancestor's position; within
            // that position the rules keep source order, which is registration
            // order for the nested names themselves.
            $this->collectRules($body, null === $layer ? $name : $layer . '.' . $name, $rules, $order);
            return;
        }

        if ( 1 === preg_match('/^@media\b(.*)$/is', $prelude, $match) ) {
            if ( null !== $body && $this->mediaConditionApplies(trim($match[1])) ) {
                $this->collectRules($body, $layer, $rules, $order);
            }
            return;
        }

        // @supports carries no viewport condition, so its rules declare
        // effective style. @keyframes interiors are not element rules, and
        // @font-face/@import/@charset declare none either.
        if ( 1 === preg_match('/^@supports\b/i', $prelude) && null !== $body ) {
            $this->collectRules($body, $layer, $rules, $order);
        }
    }

    /**
     * Split CSS into its top-level qualified rules and at-rules.
     *
     * `body` is the block interior, or null for a statement at-rule terminated
     * by `;`. Brace matching is string-aware so a `{`, `}` or `;` inside a
     * quoted value cannot end a block early.
     *
     * @return list<array{prelude: string, body: string|null}>
     */
    private function topLevelItems(string $css): array
    {
        $items = array();
        $length = strlen($css);
        $state = CssSyntaxScanner::state();
        $preludeStart = 0;
        $cursor = 0;

        while ( $cursor < $length ) {
            $character = $css[ $cursor ];

            if ( CssSyntaxScanner::isTopLevel($state) ) {
                if ( ';' === $character ) {
                    $prelude = trim(substr($css, $preludeStart, $cursor - $preludeStart));
                    if ( '' !== $prelude ) {
                        $items[] = array( 'prelude' => $prelude, 'body' => null );
                    }
                    $preludeStart = ++$cursor;
                    continue;
                }

                if ( '{' === $character ) {
                    $end = $this->matchingBrace($css, $cursor);
                    $items[] = array(
                        'prelude' => trim(substr($css, $preludeStart, $cursor - $preludeStart)),
                        'body' => substr($css, $cursor + 1, $end - $cursor - 1),
                    );
                    $preludeStart = $cursor = $end + 1;
                    continue;
                }
            }

            $cursor = CssSyntaxScanner::consume($css, $cursor, $state) ?? ( $cursor + 1 );
        }

        return $items;
    }

    /** A rule body with any nested `{ … }` blocks and their preludes removed. */
    private function withoutNestedBlocks(string $body): string
    {
        if ( ! str_contains($body, '{') ) {
            return $body;
        }

        $out = '';
        $length = strlen($body);
        $state = CssSyntaxScanner::state();
        $keepFrom = 0;
        $cursor = 0;

        while ( $cursor < $length ) {
            if ( '{' !== $body[ $cursor ] || ! CssSyntaxScanner::isTopLevel($state) ) {
                $cursor = CssSyntaxScanner::consume($body, $cursor, $state) ?? ( $cursor + 1 );
                continue;
            }
            // Drop back to the declaration boundary so the nested rule's own
            // prelude does not read as a truncated declaration.
            $preludeStart = strrpos(substr($body, 0, $cursor), ';');
            $preludeStart = false === $preludeStart ? $keepFrom : $preludeStart + 1;
            $out .= substr($body, $keepFrom, max(0, $preludeStart - $keepFrom));
            $cursor = $this->matchingBrace($body, $cursor) + 1;
            $keepFrom = $cursor;
        }

        return $out . substr($body, $keepFrom);
    }

    /**
     * Index of the `}` closing the block opened at $open.
     *
     * Unbalanced input falls back to the final byte so a truncated stylesheet
     * still contributes the rules it did declare.
     */
    private function matchingBrace(string $css, int $open): int
    {
        return CssSyntaxScanner::matchingBrace($css, $open) ?? ( strlen($css) - 1 );
    }

    /**
     * Record the layer order a stylesheet establishes, reusing the reader the
     * engine already uses to pin that order when it emits support CSS.
     */
    private function registerLayerOrder(string $css): void
    {
        foreach ( ( new AuthorCascadeLayerOrder() )->names($css) as $name ) {
            if ( ! array_key_exists($name, $this->layerPositions) ) {
                $this->layerPositions[ $name ] = count($this->layerPositions);
            }
        }
    }

    /**
     * Position of the layer a rule sits in, or null when it is unlayered.
     *
     * A layer first seen in a nested context that no `@layer` statement
     * registered takes its position from first use, which is what a browser
     * does with it.
     */
    private function layerPosition(?string $layer): ?int
    {
        if ( null === $layer ) {
            return null;
        }

        $top = strstr($layer, '.', true);
        $top = false === $top ? $layer : $top;
        if ( ! array_key_exists($top, $this->layerPositions) ) {
            $this->layerPositions[ $top ] = count($this->layerPositions);
        }

        return $this->layerPositions[ $top ];
    }

    /** Remove `/* … *&#47;` comments so they cannot be absorbed into a selector. */
    private function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
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
        return CssCascade::mediaConditionApplies($condition, self::REFERENCE_VIEWPORT_WIDTH_PX, self::ROOT_FONT_SIZE_PX);
    }

    /**
     * Deterministic specificity heuristic: 100 per #id, 10 per .class/[attr]/
     * pseudo-class, 1 per element/pseudo-element. Inline styles are applied
     * separately and always win.
     */
    private function specificity(string $selector): int
    {
        $selector = trim(preg_replace('/::?(hover|focus|active|visited|before|after)\b[^ ]*/', '', $selector) ?? $selector);
        $selector = $this->normalizeGeneratedFunctionalGuard($selector, true);

        // Read specificity off the same parse that decides matching, so a
        // selector cannot be ranked by one grammar and matched by another. The
        // regex heuristic below miscounts every shape the local matcher used to
        // reject anyway — `.md\:hidden` scored 11 (a class plus a phantom
        // `hidden` element) where CSS says 10 — and now only covers selectors the
        // production parser rejects outright.
        $parsed = CssSelectorMatcher::parse($selector);
        if ( $parsed['supported'] ) {
            return $this->parsedSpecificity($parsed['compounds']);
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

        $selector = $this->normalizeGeneratedFunctionalGuard($selector, false);

        // Matching is delegated to the production selector engine rather than
        // re-derived here. The previous local grammar accepted only `#id`,
        // `.class`, `tag`, `tag.class…` and combinator chains of those, so three
        // shapes silently matched nothing: a tagless compound (`.card.wide`), an
        // attribute selector (`.card[data-x]`), and — decisively — an escaped
        // identifier (`.md\:hidden`). Every Tailwind variant utility is escaped,
        // so the probe was blind to the entire utility layer of a Tailwind build:
        // the exact CSS that #1865 and #1879 were about. Delegating also keeps
        // matching and specificity reading one grammar instead of two.
        //
        // `:is()`/`:where()`/`:not()` come along for free, which the transformer's
        // own author-stylesheet projection emits to preserve author specificity
        // (`.footer-col ul :where(.be-source-li-…):not(be-specificity-…) a`).
        //
        // Unsupported selectors fail closed: not matching is a missing
        // declaration, while matching wrongly invents one the author never wrote.
        $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));

        return $match['supported'] && $match['matches'];
    }

    /**
     * Normalize the zero-specificity exclusion guard emitted by author-selector
     * projection into the conservative production matcher's supported grammar.
     *
     * `:not(:where(.a,.b))` is equivalent for matching to
     * `:not(.a):not(.b)`, but its specificity remains zero because every class
     * is inside :where(). For specificity calculation the whole guard therefore
     * disappears; for matching it expands to the equivalent conjunction.
     */
    private function normalizeGeneratedFunctionalGuard(string $selector, bool $forSpecificity): string
    {
        return preg_replace_callback(
            '/:not\(\s*:where\(\s*((?:\.[A-Za-z0-9_-]+\s*,\s*)*\.[A-Za-z0-9_-]+)\s*\)\s*\)/i',
            static function (array $match) use ($forSpecificity): string {
                if ( $forSpecificity ) {
                    return '';
                }
                $classes = array_values(array_filter(array_map('trim', explode(',', $match[1]))));
                return implode('', array_map(static fn (string $class): string => ':not(' . $class . ')', $classes));
            },
            $selector
        ) ?? $selector;
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

}

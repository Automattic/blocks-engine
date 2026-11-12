<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Desugars attribute-gated native CSS nesting into equivalent flat rules.
 *
 * Selector analysis and attribute/state projection read top-level style
 * rules (optionally inside conditional at-rules). A rule nested in a style
 * rule is emitted verbatim instead, which keeps working while every class it
 * names survives lowering, but a nested selector gated by a source attribute
 * (`.menu{&[data-mode=v] .item{...}}`) can never match again: lowering drops
 * the attribute, and the rule was never analyzed, so no marker replaced it.
 * Flattening those rules lets them take the flat path that already projects
 * attribute selectors; every other nested rule keeps its authored nesting.
 *
 * Resolution follows CSS Nesting: a nested selector without `&` is relative
 * to its parent (`& ` descendant, or `& > x` for a leading combinator).
 * A leading `&` of a single parent selector is substituted textually, which
 * is exact in both matching and specificity; any other position or a parent
 * selector list uses `:is(<parent>)`. Declarations and rules keep their
 * source order, so cascade order is preserved. Rules containing anything
 * this cannot flatten exactly are kept unchanged.
 */
final class CssNestingFlattener
{
    private const CONDITIONAL_AT_RULES = array( 'container', 'layer', 'media', 'supports', 'starting-style' );

    /** @var array<string, string> */
    private static array $memo = array();

    public static function flatten(string $css): string
    {
        if ( ! str_contains($css, '{') ) {
            return $css;
        }
        $key = hash('sha256', $css);
        if ( isset(self::$memo[$key]) ) {
            return self::$memo[$key];
        }
        $tree = new CssRuleTree($css);
        $result = $tree->wellFormed ? (new self($tree))->renderTop(-1) : $css;
        if ( count(self::$memo) >= 64 ) {
            self::$memo = array();
        }
        return self::$memo[$key] = $result;
    }

    private function __construct(private readonly CssRuleTree $tree)
    {
    }

    /** Render the contents of a top-level or conditional at-rule block. */
    private function renderTop(int $scope): string
    {
        $css = $this->tree->source;
        $offset = $scope + 1;
        $limit = -1 === $scope ? strlen($css) : (int) $this->tree->blockEnd($scope);
        $output = '';
        foreach ( $this->tree->rules($scope) as $rule ) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if ( '{' !== $css[$boundary] ) {
                continue;
            }
            $prelude = substr($css, $start, $boundary - $start);
            $replacement = null;
            $atRule = self::atRuleName($prelude);
            if ( null !== $atRule ) {
                if ( in_array($atRule, self::CONDITIONAL_AT_RULES, true) ) {
                    $changed = $this->renderTop($boundary);
                    if ( $changed !== substr($css, $boundary + 1, $end - $boundary - 1) ) {
                        $replacement = $prelude . '{' . $changed . '}';
                    }
                }
            } elseif ( $this->hasNestedRules($boundary) ) {
                $selector = trim(CssSyntaxScanner::withoutComments($prelude));
                if ( null !== CssStylesheetTransformer::splitSelectorList($selector) && $this->hasAttributeGatedRules($boundary, $selector) ) {
                    $replacement = $this->renderGated($boundary, $selector, array( trim($prelude) ));
                }
            }
            if ( null !== $replacement ) {
                $output .= substr($css, $offset, $start - $offset) . $replacement;
                $offset = $end + 1;
            }
        }
        return $output . substr($css, $offset, $limit - $offset);
    }

    /**
     * A body whose resolved selector is attribute-gated is flattened whole.
     * Otherwise its content keeps its authored nesting (re-wrapped in the
     * original ancestor preludes, in source order) and only attribute-gated
     * descendants are flattened.
     *
     * @param list<string> $chain Authored preludes from the top-level rule down.
     */
    private function renderGated(int $scope, string $selector, array $chain): string
    {
        if ( self::hasAttributeSelector($selector) ) {
            $flat = $this->renderBody($scope, $selector);
            if ( null !== $flat ) {
                return $flat;
            }
        }
        $css = $this->tree->source;
        $offset = $scope + 1;
        $limit = (int) $this->tree->blockEnd($scope);
        $output = '';
        foreach ( $this->tree->rules($scope) as $rule ) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if ( '{' !== $css[$boundary] ) {
                continue;
            }
            $prelude = substr($css, $start, $boundary - $start);
            if ( self::isCustomPropertyBlock($prelude) ) {
                continue;
            }
            $atRule = self::atRuleName($prelude);
            if ( null !== $atRule ) {
                if ( ! in_array($atRule, self::CONDITIONAL_AT_RULES, true) || ! $this->hasAttributeGatedRules($boundary, $selector) ) {
                    continue;
                }
                $output .= self::wrap($chain, substr($css, $offset, $start - $offset))
                    . trim($prelude) . '{' . $this->renderGated($boundary, $selector, $chain) . '}';
                $offset = $end + 1;
                continue;
            }
            $resolved = self::resolve($selector, trim(CssSyntaxScanner::withoutComments($prelude)));
            if ( null === $resolved || ! $this->hasAttributeGatedRules($boundary, $resolved) ) {
                continue;
            }
            $output .= self::wrap($chain, substr($css, $offset, $start - $offset))
                . $this->renderGated($boundary, $resolved, array( ...$chain, trim($prelude) ));
            $offset = $end + 1;
        }
        return $output . self::wrap($chain, substr($css, $offset, $limit - $offset));
    }

    /** Whether the body resolves any attribute-gated rule; a gated selector counts when its body has content. */
    private function hasAttributeGatedRules(int $scope, string $selector): bool
    {
        if ( self::hasAttributeSelector($selector) ) {
            return true;
        }
        $css = $this->tree->source;
        foreach ( $this->tree->rules($scope) as $rule ) {
            ['start' => $start, 'boundary' => $boundary] = $rule;
            if ( '{' !== $css[$boundary] ) {
                continue;
            }
            $prelude = substr($css, $start, $boundary - $start);
            if ( self::isCustomPropertyBlock($prelude) ) {
                continue;
            }
            $atRule = self::atRuleName($prelude);
            if ( null !== $atRule ) {
                if ( in_array($atRule, self::CONDITIONAL_AT_RULES, true) && $this->hasAttributeGatedRules($boundary, $selector) ) {
                    return true;
                }
                continue;
            }
            $resolved = self::resolve($selector, trim(CssSyntaxScanner::withoutComments($prelude)));
            if ( null !== $resolved && $this->hasAttributeGatedRules($boundary, $resolved) ) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $chain */
    private static function wrap(array $chain, string $content): string
    {
        if ( '' === trim(trim(CssSyntaxScanner::withoutComments($content)), ';') ) {
            return '';
        }
        foreach ( array_reverse($chain) as $prelude ) {
            $content = $prelude . '{' . $content . '}';
        }
        return $content;
    }

    private static function hasAttributeSelector(string $selector): bool
    {
        $quote = '';
        $length = strlen($selector);
        for ( $index = 0; $index < $length; ++$index ) {
            $character = $selector[$index];
            if ( '\\' === $character ) {
                ++$index;
            } elseif ( '' !== $quote ) {
                $quote = $character === $quote ? '' : $quote;
            } elseif ( '"' === $character || "'" === $character ) {
                $quote = $character;
            } elseif ( '[' === $character ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flat rules for a style-rule body (or a conditional block nested in
     * one) whose resolved selector is `$selector`; null when not exact.
     */
    private function renderBody(int $scope, string $selector): ?string
    {
        $css = $this->tree->source;
        $offset = $scope + 1;
        $limit = (int) $this->tree->blockEnd($scope);
        $output = '';
        foreach ( $this->tree->rules($scope) as $rule ) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if ( '{' !== $css[$boundary] ) {
                continue;
            }
            $prelude = substr($css, $start, $boundary - $start);
            if ( self::isCustomPropertyBlock($prelude) ) {
                continue;
            }
            $output .= self::declarationRule($selector, substr($css, $offset, $start - $offset));
            $offset = $end + 1;
            $atRule = self::atRuleName($prelude);
            if ( null !== $atRule ) {
                if ( ! in_array($atRule, self::CONDITIONAL_AT_RULES, true) ) {
                    return null;
                }
                $inner = $this->renderBody($boundary, $selector);
                if ( null === $inner ) {
                    return null;
                }
                $output .= trim($prelude) . '{' . $inner . '}';
                continue;
            }
            $resolved = self::resolve($selector, trim(CssSyntaxScanner::withoutComments($prelude)));
            if ( null === $resolved ) {
                return null;
            }
            $inner = $this->renderBody($boundary, $resolved);
            if ( null === $inner ) {
                return null;
            }
            $output .= $inner;
        }
        return $output . self::declarationRule($selector, substr($css, $offset, $limit - $offset));
    }

    private static function declarationRule(string $selector, string $declarations): string
    {
        $trimmed = trim(CssSyntaxScanner::withoutComments($declarations));
        return '' === trim($trimmed, "; \t\n\r\f") ? '' : $selector . '{' . trim($declarations) . '}';
    }

    private function hasNestedRules(int $scope): bool
    {
        $css = $this->tree->source;
        foreach ( $this->tree->rules($scope) as $rule ) {
            if ( '{' === $css[$rule['boundary']] && ! self::isCustomPropertyBlock(substr($css, $rule['start'], $rule['boundary'] - $rule['start'])) ) {
                return true;
            }
        }
        return false;
    }

    /** Resolve a nested selector list against its parent selector list. */
    public static function resolve(string $parent, string $nested): ?string
    {
        $parents = CssStylesheetTransformer::splitSelectorList($parent);
        $children = CssStylesheetTransformer::splitSelectorList($nested);
        if ( null === $parents || null === $children ) {
            return null;
        }
        $parents = array_values(array_filter(array_map('trim', $parents), static fn (string $part): bool => '' !== $part));
        if ( array() === $parents ) {
            return null;
        }
        $single = 1 === count($parents) ? $parents[0] : null;
        $wrapped = ':is(' . implode(',', $parents) . ')';
        // `:is()` cannot hold a pseudo-element, so such a parent only resolves textually.
        $wrappable = 1 !== preg_match('/::|:(?:before|after|first-line|first-letter)\b/i', $parent);
        $resolved = array();
        foreach ( $children as $child ) {
            $child = trim($child);
            if ( '' === $child ) {
                return null;
            }
            $positions = self::nestingPositions($child);
            if ( array() === $positions ) {
                $child = '& ' . $child;
                $positions = array( 0 );
            }
            $out = '';
            $cursor = 0;
            foreach ( $positions as $position ) {
                if ( ! ( 0 === $position && null !== $single ) && ! $wrappable ) {
                    return null;
                }
                $out .= substr($child, $cursor, $position - $cursor);
                $out .= ( 0 === $position && null !== $single ) ? $single : $wrapped;
                $cursor = $position + 1;
            }
            $resolved[] = $out . substr($child, $cursor);
        }
        return implode(',', $resolved);
    }

    /** @return list<int> Offsets of nesting selectors outside strings, escapes and attribute brackets. */
    private static function nestingPositions(string $selector): array
    {
        $positions = array();
        $length = strlen($selector);
        $bracket = 0;
        $quote = '';
        for ( $index = 0; $index < $length; ++$index ) {
            $character = $selector[$index];
            if ( '\\' === $character ) {
                ++$index;
                continue;
            }
            if ( '' !== $quote ) {
                if ( $character === $quote ) {
                    $quote = '';
                }
                continue;
            }
            if ( '"' === $character || "'" === $character ) {
                $quote = $character;
            } elseif ( '[' === $character ) {
                ++$bracket;
            } elseif ( ']' === $character && $bracket > 0 ) {
                --$bracket;
            } elseif ( '&' === $character && 0 === $bracket ) {
                $positions[] = $index;
            }
        }
        return $positions;
    }

    private static function atRuleName(string $prelude): ?string
    {
        $prelude = ltrim(CssSyntaxScanner::withoutComments($prelude));
        if ( ! str_starts_with($prelude, '@') ) {
            return null;
        }
        return preg_match('/^@([a-z-]+)/i', $prelude, $match) ? strtolower($match[1]) : '';
    }

    private static function isCustomPropertyBlock(string $prelude): bool
    {
        return 1 === preg_match('/^(?:\s|\/\*.*?\*\/)*--[^\s:]+\s*:/s', $prelude);
    }
}

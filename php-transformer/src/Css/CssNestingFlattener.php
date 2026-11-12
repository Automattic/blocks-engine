<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Desugars native CSS nesting into equivalent flat style rules.
 *
 * Selector analysis, attribute/state projection and every other stylesheet
 * walker read top-level style rules (optionally inside conditional
 * at-rules). Normalizing nesting once at input gives them all the same flat
 * view of the cascade instead of each one learning to descend into style
 * rules.
 *
 * Resolution follows CSS Nesting, which defines `&` as `:is(<parent>)`:
 * - a nested selector without `&` is relative to its parent (`& ` before a
 *   compound, `&` before a leading combinator);
 * - a leading `&` of a single parent selector is substituted textually,
 *   which matches the same elements with the same specificity as
 *   `:is(<parent>)` and keeps the selector plain for projection;
 * - any other position, or a parent selector list, uses `:is(<parent>)`,
 *   whose specificity is the most specific parent, exactly as nesting.
 *
 * Declarations, nested rules and comments keep their source order, so
 * cascade order is unchanged (declarations after a nested rule are a nested
 * declarations rule in CSS, and stay after it here). A conditional at-rule
 * nested in a style rule (`@media`, `@supports`, `@container`, `@layer`,
 * `@starting-style`) wraps its resolved flat rules. A style rule whose
 * nesting cannot be expressed exactly in flat CSS (another at-rule inside
 * it, or `&` standing for a pseudo-element) is kept as authored.
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
        $result = $tree->wellFormed ? (new self($tree))->renderGroup(-1) : $css;
        if ( count(self::$memo) >= 64 ) {
            self::$memo = array();
        }
        return self::$memo[$key] = $result;
    }

    private function __construct(private readonly CssRuleTree $tree)
    {
    }

    /** Render the contents of the stylesheet or of a top-level conditional at-rule. */
    private function renderGroup(int $scope): string
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
                    $inner = $this->renderGroup($boundary);
                    if ( $inner !== substr($css, $boundary + 1, $end - $boundary - 1) ) {
                        $replacement = $prelude . '{' . $inner . '}';
                    }
                }
            } elseif ( $this->hasNestedRules($boundary) ) {
                $selector = trim(CssSyntaxScanner::withoutComments($prelude));
                if ( null !== CssStylesheetTransformer::splitSelectorList($selector) ) {
                    $flat = $this->renderBody($boundary, $selector);
                    // Keep any comment that led the authored prelude.
                    $replacement = null === $flat ? null : self::leadingComments($prelude) . $flat;
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
            $output .= self::leadingComments($prelude);
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
                $output .= trim(CssSyntaxScanner::withoutComments($prelude)) . '{' . $inner . '}';
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

    /** A run of declarations as a rule for `$selector`; a comment-only run stays a bare comment. */
    private static function declarationRule(string $selector, string $declarations): string
    {
        $trimmed = trim(CssSyntaxScanner::withoutComments($declarations));
        if ( '' === trim($trimmed, "; \t\n\r\f") ) {
            return str_contains($declarations, '/*') ? trim($declarations, "; \t\n\r\f") : '';
        }
        return $selector . '{' . trim($declarations) . '}';
    }

    private static function leadingComments(string $prelude): string
    {
        return preg_match('/^(?:\s*\/\*.*?\*\/)+/s', $prelude, $match) ? trim($match[0]) : '';
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

    /** Resolve a nested selector list against its parent selector list; null when not exact. */
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
        // `&` cannot represent a pseudo-element, and `:is()` cannot hold one.
        if ( 1 === preg_match('/::|:(?:before|after|first-line|first-letter)\b/i', $parent) ) {
            return null;
        }
        $wrapped = ':is(' . implode(',', $parents) . ')';
        $resolved = array();
        foreach ( $children as $child ) {
            $child = trim($child);
            if ( '' === $child ) {
                return null;
            }
            $positions = self::nestingPositions($child);
            if ( array() === $positions ) {
                $child = ( 1 === preg_match('/^[>+~]/', $child) ? '&' : '& ' ) . $child;
                $positions = array( 0 );
            }
            $out = '';
            $cursor = 0;
            foreach ( $positions as $position ) {
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

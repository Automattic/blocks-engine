<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Safely visits style-rule selector preludes without parsing or reserializing CSS.
 */
final class CssStylesheetTransformer
{
    /**
     * Transform selector preludes in style rules, retaining all other source bytes.
     *
     * The callback receives the complete prelude, including its original whitespace
     * and comments, and must return its replacement. It may optionally return a
     * list of prelude/body pairs when a caller needs to split one source rule.
     *
     * @param callable(string, string, list<string>): string|list<array{prelude: string, body: string}> $transformSelectorPrelude
     */
    public function transform(string $stylesheet, callable $transformSelectorPrelude): string
    {
        if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
            return $stylesheet;
        }
        return $this->transformRules($stylesheet, $transformSelectorPrelude, null);
    }

    /**
     * Transform complete style rules while retaining at-rule nesting.
     *
     * @param callable(string, string): string $transformStyleRule
     */
    public function transformStyleRules(string $stylesheet, callable $transformStyleRule): string
    {
        if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
            return $stylesheet;
        }
        return $this->transformRules($stylesheet, static fn (string $prelude): string => $prelude, $transformStyleRule);
    }

    /** Transform only top-level style rules, retaining nested at-rule bodies byte-for-byte. */
    public function transformTopLevelStyleRules(string $stylesheet, callable $transformStyleRule): string
    {
        if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
            return $stylesheet;
        }
        return $this->transformRules($stylesheet, static fn (string $prelude): string => $prelude, $transformStyleRule, false);
    }

    /**
     * Separate declaration runs from nested rules without interpreting values.
     * Custom-property blocks belong to their declaration, not CSS nesting.
     *
     * @return list<array{declarations: string}|array{prelude: string, body: string, at_rule: string}>
     */
    public function splitStyleRuleBody(string $body): array
    {
        if ( ! $this->isWellFormedStylesheet($body) ) {
            return array(array('declarations' => $body));
        }
        $parts = array();
        $start = 0;
        $offset = 0;
        while ( null !== ($boundary = $this->nextRuleBoundary($body, $offset)) ) {
            if ( ';' === $body[$boundary] ) {
                $offset = $boundary + 1;
                continue;
            }
            $end = $this->matchingBrace($body, $boundary);
            if ( null === $end ) {
                return array(array('declarations' => $body));
            }
            $prelude = substr($body, $offset, $boundary - $offset);
            if ( preg_match('/^(?:\s|\/\*.*?\*\/)*--[^\s:]+\s*:/s', $prelude) ) {
                $offset = $end + 1;
                continue;
            }
            $declarations = substr($body, $start, $offset - $start);
            if ( '' !== trim($declarations) ) {
                $parts[] = array('declarations' => $declarations);
            }
            $parts[] = array(
                'prelude' => $prelude,
                'body' => substr($body, $boundary + 1, $end - $boundary - 1),
                'at_rule' => $this->isAtRule($prelude) ? self::atRuleName($prelude) : '',
            );
            $start = $offset = $end + 1;
        }
        if ( $start < strlen($body) ) {
            $parts[] = array('declarations' => substr($body, $start));
        }
        return $parts;
    }

    /**
     * Visit every style rule with its enclosing safe-to-walk at-rules.
     *
     * @param callable(string, string, list<string>): void $visitStyleRule
     */
    public function visitStyleRules(string $stylesheet, callable $visitStyleRule): void
    {
        $this->visitRules($stylesheet, $visitStyleRule, null, array());
    }

    /**
     * Visit style rules and keyframes during one stylesheet traversal.
     *
     * @param callable(string, string, list<string>): void $visitStyleRule
     * @param callable(string, string): void $visitKeyframes
     */
    public function visitStyleAndKeyframeRules(string $stylesheet, callable $visitStyleRule, callable $visitKeyframes): void
    {
        $this->visitRules($stylesheet, $visitStyleRule, $visitKeyframes, array());
    }

    /**
     * Visit every `@keyframes` rule with its animation name and its raw body.
     *
     * `visitStyleRules()` deliberately stops at `@keyframes`: its children are
     * offsets, not selectors. A caller that needs to know what state an
     * animation starts and ends in has to read them, so they are surfaced here
     * rather than by re-scanning the stylesheet with a regular expression.
     * Prefixed variants (`@-webkit-keyframes`) report the same name.
     *
     * @param callable(string, string): void $visitKeyframes
     */
    public function visitKeyframeRules(string $stylesheet, callable $visitKeyframes): void
    {
        $this->visitRules($stylesheet, static function (): void {}, $visitKeyframes, array());
    }

    /**
     * Concatenate stylesheets in order, dropping every rule that recurs later in
     * the result with the same text inside the same conditional group rules.
     *
     * The later copy has the same selectors, specificity and declarations and
     * comes after the earlier one, so the earlier copy can never win the cascade:
     * dropping it leaves every computed value unchanged. `@layer` rules are kept
     * whole and never dropped, because a layer's first appearance fixes its order.
     * Malformed input is concatenated unchanged.
     *
     * @param list<string> $stylesheets
     */
    public function concatenateWithoutRedundantRules(array $stylesheets): string
    {
        foreach ( $stylesheets as $stylesheet ) {
            if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
                return implode("\n", $stylesheets);
            }
        }

        $kept = array();
        $seen = array();
        foreach ( array_reverse($stylesheets) as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            foreach ( array_reverse($units) as $unit ) {
                if ( $unit['dedupable'] ) {
                    $key = self::ruleUnitKey($unit);
                    if ( isset($seen[ $key ]) ) {
                        continue;
                    }
                    $seen[ $key ] = true;
                }
                $kept[] = $unit;
            }
        }

        return $this->serializeRuleUnits(array_reverse($kept));
    }

    /**
     * The rules of `$stylesheets`, in order and each once, that do not occur
     * with the same text inside the same conditional group rules in `$present`.
     * Malformed input yields the stylesheets unchanged.
     *
     * @param list<string> $stylesheets
     * @param list<string> $present
     */
    public function rulesAbsentFrom(array $stylesheets, array $present): string
    {
        foreach ( array_merge($stylesheets, $present) as $stylesheet ) {
            if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
                return implode("\n", $stylesheets);
            }
        }
        $seen = array();
        foreach ( $present as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            foreach ( $units as $unit ) {
                $seen[ self::ruleUnitKey($unit) ] = true;
            }
        }
        $kept = array();
        foreach ( $stylesheets as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            foreach ( $units as $unit ) {
                $key = self::ruleUnitKey($unit);
                if ( $unit['dedupable'] && isset($seen[ $key ]) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $kept[] = $unit;
            }
        }
        return $this->serializeRuleUnits($kept);
    }

    /** @param array{context: list<string>, text: string, dedupable: bool} $unit */
    private static function ruleUnitKey(array $unit): string
    {
        return md5(implode("\0", $unit['context']) . "\0" . trim($unit['text']), true);
    }

    /** @param list<array{context: list<string>, text: string, dedupable: bool}> $units */
    private function serializeRuleUnits(array $units): string
    {
        $output = '';
        $open = array();
        foreach ( $units as $unit ) {
            $shared = 0;
            while ( $shared < count($open) && $shared < count($unit['context']) && $open[ $shared ] === $unit['context'][ $shared ] ) {
                ++$shared;
            }
            $output .= str_repeat('}', count($open) - $shared);
            foreach ( array_slice($unit['context'], $shared) as $prelude ) {
                $output .= $prelude . '{';
            }
            $open = $unit['context'];
            $output .= $unit['text'];
        }

        return $output . str_repeat('}', count($open));
    }

    /**
     * Flatten a stylesheet into rules keyed by their enclosing conditional groups.
     *
     * @param list<string> $context
     * @param list<array{context: list<string>, text: string, dedupable: bool}> $units
     */
    private function collectRuleUnits(string $css, array $context, array &$units): void
    {
        $offset = 0;
        $length = strlen($css);
        while ( $offset < $length ) {
            $boundary = $this->nextRuleBoundary($css, $offset);
            if ( null === $boundary ) {
                if ( '' !== trim(substr($css, $offset)) ) {
                    $units[] = array( 'context' => $context, 'text' => substr($css, $offset), 'dedupable' => false );
                }
                return;
            }
            $end = ';' === $css[ $boundary ] ? $boundary : $this->matchingBrace($css, $boundary);
            if ( null === $end ) {
                $units[] = array( 'context' => $context, 'text' => substr($css, $offset), 'dedupable' => false );
                return;
            }
            $prelude = substr($css, $offset, $boundary - $offset);
            $atRule = $this->isAtRule($prelude) ? self::atRuleName($prelude) : '';
            // A layer block is kept whole: distinct blocks can be distinct layers.
            if ( '{' === $css[ $boundary ] && '' !== $atRule && 'layer' !== $atRule && $this->walksNestedRules($prelude) ) {
                $nested = $context;
                $nested[] = trim($prelude);
                $this->collectRuleUnits(substr($css, $boundary + 1, $end - $boundary - 1), $nested, $units);
            } else {
                $units[] = array(
                    'context'   => $context,
                    'text'      => substr($css, $offset, $end - $offset + 1),
                    'dedupable' => ! in_array($atRule, array( 'charset', 'import', 'layer', 'namespace' ), true),
                );
            }
            $offset = $end + 1;
        }
    }

    /**
     * @return array{preamble: string, stylesheet: string}
     */
    public function splitLeadingAtRulePreamble(string $stylesheet): array
    {
        $offset = 0;
        $length = strlen($stylesheet);
        while ( $offset < $length ) {
            $boundary = $this->nextRuleBoundary($stylesheet, $offset);
            if ( null === $boundary || ';' !== $stylesheet[ $boundary ] ) {
                break;
            }

            $statement = substr($stylesheet, $offset, $boundary - $offset + 1);
            if ( ! in_array(self::atRuleName($statement), array( 'charset', 'import', 'namespace' ), true) ) {
                break;
            }
            $offset = $boundary + 1;
        }

        return array(
            'preamble'   => substr($stylesheet, 0, $offset),
            'stylesheet' => substr($stylesheet, $offset),
        );
    }

    /**
     * Split a selector list only at top-level commas. Null indicates malformed CSS.
     *
     * @return list<string>|null
     */
    public static function splitSelectorList(string $prelude): ?array
    {
        $parts  = array();
        $start  = 0;
        $state  = CssSyntaxScanner::state();
        $length = strlen($prelude);

        for ( $index = 0; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($prelude, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( ',' === $prelude[ $index ] && $topLevel && $next === $index + 1 ) {
                $parts[] = substr($prelude, $start, $index - $start);
                $start   = $index + 1;
            }
            $index = $next - 1;
        }

        if ( ! CssSyntaxScanner::isComplete($state) ) {
            return null;
        }

        $parts[] = substr($prelude, $start);
        return $parts;
    }

    /**
     * @param callable(string): string $transformSelectorPrelude
     */
    private function transformRules(string $css, callable $transformSelectorPrelude, ?callable $transformStyleRule, bool $walkNested = true, array $ancestors = array()): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($css);

        while ( $offset < $length ) {
            $boundary = $this->nextRuleBoundary($css, $offset);
            if ( null === $boundary ) {
                return $output . substr($css, $offset);
            }

            $token = $css[ $boundary ];
            if ( ';' === $token ) {
                $output .= substr($css, $offset, $boundary - $offset + 1);
                $offset = $boundary + 1;
                continue;
            }

            $blockEnd = $this->matchingBrace($css, $boundary);
            if ( null === $blockEnd ) {
                return $output . substr($css, $offset);
            }

            $prelude = substr($css, $offset, $boundary - $offset);
            if ( $this->isAtRule($prelude) ) {
                $output .= $prelude . '{';
                $body = substr($css, $boundary + 1, $blockEnd - $boundary - 1);
                $nestedAncestors = $ancestors;
                $nestedAncestors[] = trim($prelude);
                $output .= $walkNested && $this->walksNestedRules($prelude) ? $this->transformRules($body, $transformSelectorPrelude, $transformStyleRule, true, $nestedAncestors) : $body;
                $output .= '}';
            } elseif ( $this->isStylePrelude($prelude) ) {
                $body = substr($css, $boundary + 1, $blockEnd - $boundary - 1);
                if ( null !== $transformStyleRule ) {
                    $output .= $transformStyleRule($prelude, $body, $ancestors);
                } else {
                    $transformed = $transformSelectorPrelude($prelude, $body, $ancestors);
                    if ( is_array($transformed) ) {
                        foreach ( $transformed as $rule ) {
                            $output .= $rule['prelude'] . '{' . $rule['body'] . '}';
                        }
                    } else {
                        $output .= $transformed . '{' . $body . '}';
                    }
                }
            } else {
                $output .= substr($css, $offset, $blockEnd - $offset + 1);
            }

            $offset = $blockEnd + 1;
        }

        return $output;
    }

    /** @param callable(string, string, list<string>): void $visitStyleRule @param callable(string, string): void|null $visitKeyframes @param list<string> $ancestors */
    private function visitRules(string $css, callable $visitStyleRule, ?callable $visitKeyframes, array $ancestors): void
    {
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $boundary = $this->nextRuleBoundary($css, $offset);
            if (null === $boundary || ';' === $css[$boundary]) {
                $offset = null === $boundary ? $length : $boundary + 1;
                continue;
            }
            $blockEnd = $this->matchingBrace($css, $boundary);
            if (null === $blockEnd) {
                return;
            }
            $prelude = substr($css, $offset, $boundary - $offset);
            $body = substr($css, $boundary + 1, $blockEnd - $boundary - 1);
            if ($this->isAtRule($prelude)) {
                $name = self::atRuleName($prelude);
                if (null !== $visitKeyframes && ('keyframes' === $name || str_ends_with($name, '-keyframes'))) {
                    $animationName = self::keyframesAnimationName($prelude);
                    if ('' !== $animationName) {
                        $visitKeyframes($animationName, $body);
                    }
                } elseif ($this->walksNestedRules($prelude)) {
                    $nested = $ancestors;
                    $nested[] = trim($prelude);
                    $this->visitRules($body, $visitStyleRule, $visitKeyframes, $nested);
                }
            } elseif ($this->isStylePrelude($prelude)) {
                $visitStyleRule($prelude, $body, $ancestors);
            }
            $offset = $blockEnd + 1;
        }
    }

    private static function keyframesAnimationName(string $prelude): string
    {
        $name = trim((string) preg_replace('#/\\*.*?\\*/#s', ' ', $prelude));
        $name = trim((string) preg_replace('/^@[A-Za-z-]+/', '', $name));
        if ( 2 <= strlen($name) && ( ('"' === $name[0] && '"' === substr($name, -1)) || ("'" === $name[0] && "'" === substr($name, -1)) ) ) {
            $name = substr($name, 1, -1);
        }

        return trim($name);
    }

    private function nextRuleBoundary(string $css, int $offset): ?int
    {
        $state  = CssSyntaxScanner::state();
        $length = strlen($css);
        for ( $index = $offset; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $index + 1 && ('{' === $css[ $index ] || ';' === $css[ $index ]) ) {
                return $index;
            }
            $index = $next - 1;
        }

        return null;
    }

    private function matchingBrace(string $css, int $openingBrace): ?int
    {
        $state  = CssSyntaxScanner::state();
        $depth  = 0;
        $length = strlen($css);
        for ( $index = $openingBrace; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $index + 1 && '{' === $css[ $index ] ) {
                ++$depth;
            } elseif ( $topLevel && $next === $index + 1 && '}' === $css[ $index ] && 0 === --$depth ) {
                return $index;
            }
            $index = $next - 1;
        }

        return null;
    }

    private function isAtRule(string $prelude): bool
    {
        return '@' === self::firstSignificantCharacter($prelude);
    }

    private function isStylePrelude(string $prelude): bool
    {
        return '' !== self::firstSignificantCharacter($prelude) && null !== self::splitSelectorList($prelude);
    }

    private function walksNestedRules(string $prelude): bool
    {
        return in_array(self::atRuleName($prelude), array( 'container', 'layer', 'media', 'scope', 'starting-style', 'supports' ), true);
    }

    private function isWellFormedStylesheet(string $css): bool
    {
        $state = CssSyntaxScanner::state();
        $braces = 0;
        $length = strlen($css);
        for ( $offset = 0; $offset < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return false;
            }
            if ( $topLevel && $next === $offset + 1 ) {
                if ( '{' === $css[ $offset ] ) {
                    ++$braces;
                } elseif ( '}' === $css[ $offset ] ) {
                    if ( --$braces < 0 ) {
                        return false;
                    }
                }
            }
            $offset = $next;
        }
        return 0 === $braces && CssSyntaxScanner::isComplete($state);
    }

    private static function firstSignificantCharacter(string $value): string
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ( $offset = 0; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ( null === $next ) {
                return '';
            }
            if ( $next === $offset + 1 && CssSyntaxScanner::isTopLevel($state) && ! CssSyntaxScanner::isCssWhitespace($value[ $offset ]) ) {
                return $value[ $offset ];
            }
            $offset = $next;
        }
        return '';
    }

    private static function atRuleName(string $prelude): string
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($prelude);
        $seenAt = false;
        $name = '';
        for ( $offset = 0; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($prelude, $offset, $state);
            if ( null === $next ) {
                return '';
            }
            if ( $next !== $offset + 1 || ! CssSyntaxScanner::isTopLevel($state) ) {
                $offset = $next;
                continue;
            }
            $character = $prelude[ $offset ];
            if ( ! $seenAt ) {
                if ( CssSyntaxScanner::isCssWhitespace($character) ) { $offset = $next; continue; }
                if ( '@' !== $character ) { return ''; }
                $seenAt = true;
            } elseif ( ctype_alpha($character) || '-' === $character ) {
                $name .= strtolower($character);
            } else {
                return $name;
            }
            $offset = $next;
        }
        return $name;
    }
}

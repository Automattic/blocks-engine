<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Bounded, source-preserving CSS rule analysis for consumers that need declared
 * cascade facts without implementing a second stylesheet parser.
 */
final class CssRuleAnalyzer
{
    /**
     * @param list<array<string, mixed>> $stylesheets
     * @param list<string> $properties
     * @param callable(array<string, mixed>): bool|null $retainSelector
     * @return array{rules: list<array<string, mixed>>, diagnostics: list<string>, truncated: bool}
     */
    public function analyze(array $stylesheets, string $inlineCss, array $properties, int $maxCssBytes, int $maxRules, int $maxSelectors, int $maxConditionDepth, ?callable $retainSelector = null, ?int $maxScannedSelectors = null): array
    {
        $result = array( 'rules' => array(), 'diagnostics' => array(), 'truncated' => false );
        $order = 0;
        $retainedSelectorCount = 0;
        $scannedSelectorCount = 0;
        $scanLimitReached = false;
        $maxScannedSelectors ??= $maxSelectors;

        foreach ( $stylesheets as $sheet ) {
            $css = (string) ( $sheet['content'] ?? '' );
            $layerRanks = array();
            foreach ( ( new AuthorCascadeLayerOrder() )->names($css) as $index => $name ) {
                $layerRanks[ $name ] = $index;
            }
            $this->analyzeStylesheet(
                $css,
                (string) ( $sheet['source_path'] ?? $sheet['path'] ?? '' ),
                (string) ( $sheet['source_hash'] ?? '' ),
                $this->linkCondition($sheet),
                $properties,
                $maxCssBytes,
                $maxRules,
                $maxSelectors,
                $maxConditionDepth,
                $result,
                $order,
                $retainedSelectorCount,
                $scannedSelectorCount,
                $scanLimitReached,
                $retainSelector,
                $maxScannedSelectors,
                $layerRanks
            );
            if ( $scanLimitReached ) {
                break;
            }
        }

        if ( array() === $stylesheets && '' !== trim($inlineCss) ) {
            $layerRanks = array();
            foreach ( ( new AuthorCascadeLayerOrder() )->names($inlineCss) as $index => $name ) {
                $layerRanks[ $name ] = $index;
            }
            $this->analyzeStylesheet($inlineCss, 'inline-style', hash('sha256', $inlineCss), null, $properties, $maxCssBytes, $maxRules, $maxSelectors, $maxConditionDepth, $result, $order, $retainedSelectorCount, $scannedSelectorCount, $scanLimitReached, $retainSelector, $maxScannedSelectors, $layerRanks);
        }

        $result['diagnostics'] = array_values(array_unique($result['diagnostics']));
        return $result;
    }

    /** @param array<string, mixed> $sheet */
    private function linkCondition(array $sheet): ?array
    {
        $media = trim((string) ($sheet['media'] ?? ''));
        return '' === $media ? null : array( 'kind' => 'media', 'query' => $media );
    }

    /**
     * @param list<string> $properties
     * @param array{rules: list<array<string, mixed>>, diagnostics: list<string>, truncated: bool} $result
     * @param array<string, int> $layerRanks
     */
    private function analyzeStylesheet(string $css, string $path, string $hash, ?array $condition, array $properties, int $maxCssBytes, int $maxRules, int $maxSelectors, int $maxConditionDepth, array &$result, int &$order, int &$retainedSelectorCount, int &$scannedSelectorCount, bool &$scanLimitReached, ?callable $retainSelector, int $maxScannedSelectors, array &$layerRanks, ?int $layer = null, int $conditionDepth = 0): void
    {
        if ( $scanLimitReached ) {
            return;
        }
        if ( strlen($css) > $maxCssBytes ) {
            $css = substr($css, 0, $maxCssBytes);
            $result['truncated'] = true;
            $result['diagnostics'][] = 'css_bytes_truncated:' . $path;
        }
        for ( $offset = 0, $length = strlen($css); $offset < $length; ) {
            $boundary = $this->nextRuleBoundary($css, $offset);
            if ( null === $boundary ) {
                if ( $this->hasNonTrivia($css, $offset) ) {
                    $result['diagnostics'][] = 'malformed_stylesheet:' . $path;
                }
                return;
            }

            if ( ';' === $css[ $boundary ] ) {
                $offset = $boundary + 1;
                continue;
            }

            $end = $this->matchingBrace($css, $boundary);
            if ( null === $end ) {
                $result['diagnostics'][] = 'malformed_stylesheet:' . $path;
                return;
            }

            $prelude = trim(substr($css, $offset, $boundary - $offset));
            $body = substr($css, $boundary + 1, $end - $boundary - 1);
            $atRule = $this->atRule($prelude);
            if ( null !== $atRule ) {
                if ( in_array($atRule['name'], array( 'media', 'container', 'supports' ), true) ) {
                    if ( $conditionDepth >= $maxConditionDepth ) {
                        $result['truncated'] = true;
                        $result['diagnostics'][] = 'condition_depth_limit';
                        return;
                    }
                    $this->analyzeStylesheet($body, $path, $hash, $this->combineCondition($condition, array( 'kind' => $atRule['name'], 'query' => $atRule['query'] )), $properties, $maxCssBytes, $maxRules, $maxSelectors, $maxConditionDepth, $result, $order, $retainedSelectorCount, $scannedSelectorCount, $scanLimitReached, $retainSelector, $maxScannedSelectors, $layerRanks, $layer, $conditionDepth + 1);
                    if ( $scanLimitReached ) {
                        return;
                    }
                } elseif ( 'layer' === $atRule['name'] ) {
                    // A cascade layer block gates cascade priority, not whether its
                    // declarations apply at all - unlike media/container/supports it
                    // is not a condition, so its contents are analyzed at the same
                    // condition depth and without adding a condition gate. The layer
                    // rank is recorded so consumers can apply cascade-layer precedence
                    // instead of treating every layered rule as unlayered.
                    $this->analyzeStylesheet($body, $path, $hash, $condition, $properties, $maxCssBytes, $maxRules, $maxSelectors, $maxConditionDepth, $result, $order, $retainedSelectorCount, $scannedSelectorCount, $scanLimitReached, $retainSelector, $maxScannedSelectors, $layerRanks, $this->layerRank($layerRanks, $atRule['query']), $conditionDepth);
                    if ( $scanLimitReached ) {
                        return;
                    }
                }
                $offset = $end + 1;
                continue;
            }
            if ( $this->isAtRulePrelude($prelude) ) {
                // Nested non-style at-rules (@property, @keyframes, @font-face, …)
                // are opaque blocks, not selectors. Recursing into @layer exposes
                // them; treating them as style rules would abort the rest of the
                // stylesheet as malformed or pollute the cascade with junk facts.
                $offset = $end + 1;
                continue;
            }

            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if ( null === $selectors ) {
                $result['diagnostics'][] = 'malformed_stylesheet:' . $path;
                return;
            }
            $declarations = $this->declarations($body, $properties);
            foreach ( $selectors as $selector ) {
                $selector = trim($this->normalizeSelectorComments($selector));
                if ( '' === $selector ) {
                    continue;
                }
                if ( array() === $declarations ) {
                    continue;
                }
                if ( ++$scannedSelectorCount > $maxScannedSelectors ) {
                    $result['truncated'] = true;
                    $result['diagnostics'][] = 'css_selector_scan_limit';
                    $scanLimitReached = true;
                    return;
                }
                $parsed = CssSelectorMatcher::parse($selector);
                if ( null !== $retainSelector && ! $retainSelector($parsed) ) {
                    continue;
                }
                if ( $retainedSelectorCount >= $maxSelectors || count($result['rules']) >= $maxRules ) {
                    $result['truncated'] = true;
                    $result['diagnostics'][] = 'css_rule_or_selector_limit';
                    return;
                }
                ++$retainedSelectorCount;
                $result['rules'][] = array(
                    'selector' => $selector,
                    'parsed_selector' => $parsed,
                    'declarations' => $declarations,
                    'condition' => $condition,
                    'layer' => $layer,
                    'path' => $path,
                    'hash' => $hash,
                    'order' => $order++,
                    'specificity' => CssSelectorMatcher::specificity($parsed),
                );
            }
            $offset = $end + 1;
        }
    }

    /** @return list<array{name: string, value: string}> */
    public static function declarations(string $body, array $properties): array
    {
        $declarations = array();
        $start = 0;
        $state = CssSyntaxScanner::state();
        $length = strlen($body);
        for ( $offset = 0; $offset <= $length; ++$offset ) {
            $boundary = $offset === $length || ( ';' === $body[ $offset ] && CssSyntaxScanner::isTopLevel($state) );
            if ( $boundary ) {
                $declaration = trim(substr($body, $start, $offset - $start));
                $start = $offset + 1;
                $colon = self::topLevelColon($declaration);
                if ( null !== $colon ) {
                    $name = trim(substr($declaration, 0, $colon));
                    // Custom property names are case-sensitive; ordinary CSS property
                    // names retain their case-insensitive normalization.
                    if ( ! str_starts_with($name, '--') ) {
                        $name = strtolower($name);
                    }
                    $value = trim(substr($declaration, $colon + 1));
                    if ( ( in_array($name, $properties, true) || ( in_array('--*', $properties, true) && str_starts_with($name, '--') ) ) && '' !== $value ) {
                        $declarations[] = array( 'name' => $name, 'value' => preg_replace('/\s+/', ' ', $value) ?? $value );
                    }
                }
                continue;
            }
            $next = CssSyntaxScanner::consume($body, $offset, $state);
            if ( null === $next ) {
                return array();
            }
            $offset = $next - 1;
        }
        return CssSyntaxScanner::isComplete($state) ? $declarations : array();
    }

    private static function topLevelColon(string $value): ?int
    {
        $state = CssSyntaxScanner::state();
        for ( $offset = 0, $length = strlen($value); $offset < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ( null === $next ) {
                return null;
            }
            if ( ':' === $value[ $offset ] && $topLevel ) {
                return $offset;
            }
            $offset = $next;
        }
        return null;
    }

    /** @return array{name: string, query: string}|null */
    private function atRule(string $prelude): ?array
    {
        $prelude = $this->normalizeAtRuleComments($prelude);
        if ( preg_match('/^@(media|container|supports)\s+(.+)$/i', $prelude, $match) ) {
            return array( 'name' => strtolower($match[1]), 'query' => trim($match[2]) );
        }
        // A cascade layer block's name is optional (an anonymous layer is valid
        // CSS) and, unlike media/container/supports, carries no required query.
        if ( preg_match('/^@layer\b\s*(.*)$/i', $prelude, $match) ) {
            return array( 'name' => 'layer', 'query' => trim($match[1]) );
        }
        return null;
    }

    /**
     * @param array<string, int> $layerRanks
     */
    private function layerRank(array &$layerRanks, string $query): int
    {
        $names = array();
        foreach ( explode(',', $query) as $candidate ) {
            $candidate = strtolower(trim($candidate));
            if ( '' === $candidate ) {
                continue;
            }
            if ( 1 !== preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/', $candidate) ) {
                $names = array();
                break;
            }
            $top = strstr($candidate, '.', true);
            $names[] = false === $top ? $candidate : $top;
        }
        if ( array() === $names ) {
            $rank = count($layerRanks);
            $layerRanks[ '#anon-' . $rank ] = $rank;
            return $rank;
        }
        $name = $names[0];
        $layerRanks[ $name ] ??= count($layerRanks);
        return $layerRanks[ $name ];
    }

    private function isAtRulePrelude(string $prelude): bool
    {
        $prelude = $this->normalizeAtRuleComments($prelude);
        $prelude = ltrim($prelude);
        return '' !== $prelude && '@' === $prelude[0];
    }

    private function combineCondition(?array $left, array $right): array
    {
        if ( null === $left ) {
            return $right;
        }
        return array( 'kind' => 'all', 'conditions' => 'all' === ($left['kind'] ?? null) ? array_merge($left['conditions'], array($right)) : array($left, $right) );
    }

    private function nextRuleBoundary(string $css, int $offset): ?int
    {
        $state = CssSyntaxScanner::state();
        for ( $length = strlen($css); $offset < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $offset + 1 && ( '{' === $css[ $offset ] || ';' === $css[ $offset ] ) ) {
                return $offset;
            }
            $offset = $next;
        }
        return null;
    }

    /**
     * A stylesheet may legally end with whitespace and comments, including a
     * source-map comment. Preserve malformed-input diagnostics for every other
     * incomplete trailing token.
     */
    private function hasNonTrivia(string $css, int $offset): bool
    {
        $state = CssSyntaxScanner::state();
        for ($length = strlen($css); $offset < $length; ) {
            $insideComment = $state['comment'];
            $startsComment = ! $insideComment && '' === $state['quote'] && '/*' === substr($css, $offset, 2);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return true;
            }
            if (! $insideComment && ! $startsComment && ! CssSyntaxScanner::isCssWhitespace($css[$offset])) {
                return true;
            }
            $offset = $next;
        }
        return ! CssSyntaxScanner::isComplete($state);
    }

    /**
     * CSS comments disappear from selectors, but adjacent identifier-like tokens
     * need a separator to avoid changing a descendant selector into one token.
     */
    private function normalizeSelectorComments(string $selector): string
    {
        $output = '';
        $state = CssSyntaxScanner::state();
        for ( $offset = 0, $length = strlen($selector); $offset < $length; ) {
            $insideComment = $state['comment'];
            $startsComment = ! $insideComment && '' === $state['quote'] && '/*' === substr($selector, $offset, 2);
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ( null === $next ) {
                return $selector;
            }
            if ( $startsComment ) {
                $commentEnd = strpos($selector, '*/', $offset + 2);
                $before = $output === '' ? '' : substr($output, -1);
                $after = false === $commentEnd ? '' : ($selector[ $commentEnd + 2 ] ?? '');
                if ( self::identifierLike($before) && self::identifierLike($after) ) {
                    $output .= ' ';
                }
            } elseif ( ! $insideComment ) {
                $output .= substr($selector, $offset, $next - $offset);
            }
            $offset = $next;
        }
        return $output;
    }

    private function normalizeAtRuleComments(string $prelude): string
    {
        return preg_replace('~/\*.*?\*/~s', ' ', $prelude) ?? $prelude;
    }

    private static function identifierLike(string $character): bool
    {
        return '' !== $character && (ctype_alnum($character) || '_' === $character || '-' === $character || '\\' === $character);
    }

    private function matchingBrace(string $css, int $openingBrace): ?int
    {
        $state = CssSyntaxScanner::state();
        $depth = 0;
        for ( $offset = $openingBrace, $length = strlen($css); $offset < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $offset + 1 && '{' === $css[ $offset ] ) {
                ++$depth;
            } elseif ( $topLevel && $next === $offset + 1 && '}' === $css[ $offset ] && 0 === --$depth ) {
                return $offset;
            }
            $offset = $next;
        }
        return null;
    }
}

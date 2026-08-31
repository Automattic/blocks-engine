<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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
            $this->analyzeStylesheet(
                (string) ( $sheet['content'] ?? '' ),
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
                $maxScannedSelectors
            );
            if ( $scanLimitReached ) {
                break;
            }
        }

        if ( array() === $stylesheets && '' !== trim($inlineCss) ) {
            $this->analyzeStylesheet($inlineCss, 'inline-style', hash('sha256', $inlineCss), null, $properties, $maxCssBytes, $maxRules, $maxSelectors, $maxConditionDepth, $result, $order, $retainedSelectorCount, $scannedSelectorCount, $scanLimitReached, $retainSelector, $maxScannedSelectors);
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
     */
    private function analyzeStylesheet(string $css, string $path, string $hash, ?array $condition, array $properties, int $maxCssBytes, int $maxRules, int $maxSelectors, int $maxConditionDepth, array &$result, int &$order, int &$retainedSelectorCount, int &$scannedSelectorCount, bool &$scanLimitReached, ?callable $retainSelector, int $maxScannedSelectors, int $conditionDepth = 0): void
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
                if ( '' !== trim(substr($css, $offset)) ) {
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
                    $this->analyzeStylesheet($body, $path, $hash, $this->combineCondition($condition, array( 'kind' => $atRule['name'], 'query' => $atRule['query'] )), $properties, $maxCssBytes, $maxRules, $maxSelectors, $maxConditionDepth, $result, $order, $retainedSelectorCount, $scannedSelectorCount, $scanLimitReached, $retainSelector, $maxScannedSelectors, $conditionDepth + 1);
                    if ( $scanLimitReached ) {
                        return;
                    }
                }
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
                    $name = strtolower(trim(substr($declaration, 0, $colon)));
                    $value = trim(substr($declaration, $colon + 1));
                    if ( in_array($name, $properties, true) && '' !== $value ) {
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
        if ( ! preg_match('/^@(media|container|supports)\s+(.+)$/i', $prelude, $match) ) {
            return null;
        }
        return array( 'name' => strtolower($match[1]), 'query' => trim($match[2]) );
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

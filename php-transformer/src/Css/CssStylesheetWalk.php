<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * One pass over a stylesheet, flattening its style rules and diagnostics into
 * the source-ordered stream CssRuleAnalyzer caches and replays.
 */
final class CssStylesheetWalk
{
    /** @var list<array<string, mixed>> */
    private array $stream = array();
    /** @var array<string, int> */
    private array $layerRanks = array();

    /** @param list<string> $properties */
    public function __construct(
        private readonly CssRuleTreeCache $ruleTrees,
        private readonly string $path,
        private readonly string $hash,
        private readonly array $properties,
        private readonly int $maxCssBytes,
        private readonly int $maxConditionDepth
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function stream(string $css, ?array $condition): array
    {
        foreach ( ( new AuthorCascadeLayerOrder() )->names($css) as $index => $name ) {
            $this->layerRanks[ $name ] = $index;
        }
        $this->walkStylesheet($css, $condition, null, 0);
        return $this->stream;
    }

    private function walkStylesheet(string $css, ?array $condition, ?int $layer, int $conditionDepth): void
    {
        $blockEntries = array();
        $this->walkBlock($css, $condition, $layer, $conditionDepth, $blockEntries);
        $end = count($this->stream);
        foreach ( $blockEntries as $index ) {
            $this->stream[ $index ]['block_end'] = $end;
        }
    }

    /** @param list<int> $blockEntries */
    private function walkBlock(string $css, ?array $condition, ?int $layer, int $conditionDepth, array &$blockEntries): void
    {
        $diagnostic = function (string $code, bool $truncated): void {
            $this->stream[] = array( 'diagnostic' => $code, 'truncated' => $truncated );
        };
        if ( strlen($css) > $this->maxCssBytes ) {
            $css = substr($css, 0, $this->maxCssBytes);
            $diagnostic('css_bytes_truncated:' . $this->path, true);
        }
        // One tree lookup per block: the cache keys trees by stylesheet text,
        // so a per-rule lookup re-compares the whole sheet for every rule.
        $tree = CssRuleTreeCache::parse($css, $this->ruleTrees);
        for ( $offset = 0, $length = strlen($css); $offset < $length; ) {
            $boundary = $tree->nextBoundary($offset);
            if ( null === $boundary ) {
                if ( $this->hasNonTrivia($css, $offset) ) {
                    $diagnostic('malformed_stylesheet:' . $this->path, false);
                }
                return;
            }

            if ( ';' === $css[ $boundary ] ) {
                $offset = $boundary + 1;
                continue;
            }

            $end = $tree->blockEnd($boundary);
            if ( null === $end ) {
                $diagnostic('malformed_stylesheet:' . $this->path, false);
                return;
            }

            if ( $tree->hasInvalidPrelude($offset) ) {
                // A stray root `}` invalidates only the rule it precedes.
                $offset = $end + 1;
                continue;
            }
            $prelude = trim(substr($css, $offset, $boundary - $offset));
            $body = substr($css, $boundary + 1, $end - $boundary - 1);
            $atRule = $this->atRule($prelude);
            if ( null !== $atRule ) {
                if ( in_array($atRule['name'], array( 'media', 'container', 'supports' ), true) ) {
                    if ( $conditionDepth >= $this->maxConditionDepth ) {
                        $diagnostic('condition_depth_limit', true);
                        return;
                    }
                    $this->walkStylesheet($body, $this->combineCondition($condition, array( 'kind' => $atRule['name'], 'query' => $atRule['query'] )), $layer, $conditionDepth + 1);
                } elseif ( 'layer' === $atRule['name'] ) {
                    // A cascade layer block gates cascade priority, not whether its
                    // declarations apply at all - unlike media/container/supports it
                    // is not a condition, so its contents are analyzed at the same
                    // condition depth and without adding a condition gate. The layer
                    // rank is recorded so consumers can apply cascade-layer precedence
                    // instead of treating every layered rule as unlayered.
                    $this->walkStylesheet($body, $condition, $this->layerRank($atRule['query']), $conditionDepth);
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
                $diagnostic('malformed_stylesheet:' . $this->path, false);
                return;
            }
            $declarations = CssRuleAnalyzer::declarations($body, $this->properties);
            if ( array() === $declarations ) {
                $offset = $end + 1;
                continue;
            }
            foreach ( $selectors as $selector ) {
                $selector = trim($this->normalizeSelectorComments($selector));
                if ( '' === $selector ) {
                    continue;
                }
                $parsed = CssSelectorMatcher::parse($selector);
                $blockEntries[] = count($this->stream);
                $this->stream[] = array(
                    'selector' => $selector,
                    'parsed_selector' => $parsed,
                    'declarations' => $declarations,
                    'condition' => $condition,
                    'layer' => $layer,
                    'path' => $this->path,
                    'hash' => $this->hash,
                    'specificity' => CssSelectorMatcher::specificity($parsed),
                );
            }
            $offset = $end + 1;
        }
    }

    /** @return array{name: string, query: string}|null */
    private function atRule(string $prelude): ?array
    {
        $prelude = $this->normalizeAtRuleComments($prelude);
        // A condition may follow the name directly: `@media(max-width:600px)`
        // is the same rule as `@media (max-width:600px)`.
        if ( preg_match('/^@(media|container|supports)(?:\s+|(?=\())(.+)$/is', $prelude, $match) ) {
            return array( 'name' => strtolower($match[1]), 'query' => trim($match[2]) );
        }
        // A cascade layer block's name is optional (an anonymous layer is valid
        // CSS) and, unlike media/container/supports, carries no required query.
        if ( preg_match('/^@layer\b\s*(.*)$/i', $prelude, $match) ) {
            return array( 'name' => 'layer', 'query' => trim($match[1]) );
        }
        return null;
    }

    private function layerRank(string $query): int
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
            $rank = count($this->layerRanks);
            $this->layerRanks[ '#anon-' . $rank ] = $rank;
            return $rank;
        }
        $name = $names[0];
        $this->layerRanks[ $name ] ??= count($this->layerRanks);
        return $this->layerRanks[ $name ];
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
}

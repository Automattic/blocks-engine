<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Bounded, source-preserving CSS rule analysis for consumers that need declared
 * cascade facts without implementing a second stylesheet parser.
 */
final class CssRuleAnalyzer
{
    private ?CssRuleTreeCache $ruleTrees = null;

    /** @return array<string, true> Id selectors, independent of declaration values. */
    public static function referencedIds(string $css): array
    {
        $ids = array();
        $tree = CssRuleTreeCache::parse($css);
        for ($offset = 0; null !== ($boundary = $tree->nextBoundary($offset));) {
            if (';' === $css[$boundary]) { $offset = $boundary + 1; continue; }
            $end = $tree->blockEnd($boundary);
            if (null === $end) break;
            $prelude = trim(CssSyntaxScanner::withoutComments(substr($css, $offset, $boundary - $offset)));
            if (preg_match('/^@(media|supports|container|layer|scope|starting-style)\b/i', $prelude)) {
                $ids += self::referencedIds(substr($css, $boundary + 1, $end - $boundary - 1));
            }
            if (!str_starts_with($prelude, '@')) {
                foreach (CssSelectorMatcher::referencedIds($prelude) as $id) $ids[$id] = true;
            }
            $offset = $end + 1;
        }
        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $stylesheets
     * @param list<string> $properties
     * @param callable(array<string, mixed>, string): bool|null $retainSelector Called with the parsed selector and its source text, so a retention filter can memoize matches per selector without re-deriving a key.
     * @param int|null $maxScannedSelectors Work budget for selectors accepted by the retention filter; rejected selectors are bounded by stylesheet bytes instead.
     * @return array{rules: list<array<string, mixed>>, diagnostics: list<string>, truncated: bool}
     */
    public function analyze(array $stylesheets, string $inlineCss, array $properties, int $maxCssBytes, int $maxRules, int $maxSelectors, int $maxConditionDepth, ?callable $retainSelector = null, ?int $maxScannedSelectors = null): array
    {
        $result = array( 'rules' => array(), 'diagnostics' => array(), 'truncated' => false );
        $counters = array( 'order' => 0, 'retained' => 0, 'scanned' => 0, 'max_scanned' => $maxScannedSelectors ?? $maxSelectors );

        foreach ( $stylesheets as $sheet ) {
            // cascade_content is the sheet with its @imports expanded where they occur.
            $css = (string) ( $sheet['cascade_content'] ?? $sheet['content'] ?? '' );
            $stream = $this->stylesheetStream($css, (string) ( $sheet['source_path'] ?? $sheet['path'] ?? '' ), (string) ( $sheet['source_hash'] ?? '' ), $this->linkCondition($sheet), $maxCssBytes, $maxConditionDepth);
            if ( ! $this->replay($stream, $properties, $maxRules, $maxSelectors, $retainSelector, $result, $counters) ) {
                break;
            }
        }

        if ( array() === $stylesheets && '' !== trim($inlineCss) ) {
            $stream = $this->stylesheetStream($inlineCss, 'inline-style', hash('sha256', $inlineCss), null, $maxCssBytes, $maxConditionDepth);
            $this->replay($stream, $properties, $maxRules, $maxSelectors, $retainSelector, $result, $counters);
        }

        $result['diagnostics'] = array_values(array_unique($result['diagnostics']));
        return $result;
    }

    /**
     * Parsed stylesheets shared by every analysis in the process. A page asks
     * the same questions of the same stylesheets once per form, widget, and
     * pass; only the selector retention and limits differ between callers.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private static array $streams = array();
    /**
     * The cache is bounded by the selector entries it holds, not by stream
     * count: one page can need hundreds of small streams (stylesheets times
     * property lists), while a few huge stylesheets must still evict. 100k
     * entries accommodates large pages without retaining unbounded streams.
     */
    private const STREAM_CACHE_ENTRY_BUDGET = 100000;
    private static int $streamEntries = 0;
    private static int $stylesheetWalks = 0;
    private static int $streamedRules = 0;

    /** Selector entries streamed on cache misses, observable independently of timing. */
    public static function streamedRules(): int
    {
        return self::$streamedRules;
    }

    /** Number of stylesheet walks performed since process start; observable for tests. */
    public static function stylesheetWalks(): int
    {
        return self::$stylesheetWalks;
    }

    /**
     * The retention-independent analysis of one stylesheet: its style rules'
     * selectors with all declarations and their diagnostics, in source
     * order. Each entry records where its condition or layer block ends, so a
     * replay that reaches a limit can leave that block exactly as the walk
     * would have.
     *
     * @return list<array<string, mixed>>
     */
    private function stylesheetStream(string $css, string $path, string $hash, ?array $condition, int $maxCssBytes, int $maxConditionDepth): array
    {
        $key = hash('xxh128', $css) . "\0" . serialize(array( $path, $hash, $condition, $maxCssBytes, $maxConditionDepth ));
        if ( isset(self::$streams[ $key ]) ) {
            $stream = self::$streams[ $key ];
            unset(self::$streams[ $key ]);
            return self::$streams[ $key ] = $stream;
        }
        ++self::$stylesheetWalks;
        $stream = ( new CssStylesheetWalk($this->ruleTrees ??= new CssRuleTreeCache(), $path, $hash, array('*'), $maxCssBytes, $maxConditionDepth) )->stream($css, $condition);
        self::$streamedRules += count(array_filter($stream, static fn (array $entry): bool => isset($entry['selector'])));
        if ( count($stream) > self::STREAM_CACHE_ENTRY_BUDGET ) {
            return $stream;
        }
        while ( self::$streamEntries + count($stream) > self::STREAM_CACHE_ENTRY_BUDGET ) {
            $oldest                = array_key_first(self::$streams);
            self::$streamEntries -= count(self::$streams[ $oldest ]);
            unset(self::$streams[ $oldest ]);
        }
        self::$streamEntries += count($stream);
        return self::$streams[ $key ] = $stream;
    }

    /**
     * Apply one caller's retention and limits to a stylesheet stream.
     *
     * @param list<array<string, mixed>> $stream
     * @param list<string> $properties
     * @param array{rules: list<array<string, mixed>>, diagnostics: list<string>, truncated: bool} $result
     * @param array{order: int, retained: int, scanned: int, max_scanned: int} $counters
     * @return bool False once the selector scan limit stops the analysis.
     */
    private function replay(array $stream, array $properties, int $maxRules, int $maxSelectors, ?callable $retainSelector, array &$result, array &$counters): bool
    {
        for ( $index = 0, $count = count($stream); $index < $count; ) {
            $entry = $stream[ $index ];
            if ( isset($entry['diagnostic']) ) {
                $result['diagnostics'][] = $entry['diagnostic'];
                $result['truncated'] = $result['truncated'] || $entry['truncated'];
                ++$index;
                continue;
            }
            $entry['declarations'] = array_values(array_filter($entry['declarations'], static fn (array $declaration): bool => in_array('*', $properties, true) || in_array($declaration['name'], $properties, true) || (in_array('--*', $properties, true) && str_starts_with($declaration['name'], '--'))));
            if ( array() === $entry['declarations'] ) {
                ++$index;
                continue;
            }
            // Unrelated rules must not exhaust a consumer's cascade budget.
            // Parsing the full stream remains bounded by maxCssBytes.
            if ( null !== $retainSelector && ! $retainSelector($entry['parsed_selector'], $entry['selector']) ) {
                ++$index;
                continue;
            }
            if ( ++$counters['scanned'] > $counters['max_scanned'] ) {
                $result['truncated'] = true;
                $result['diagnostics'][] = 'css_selector_scan_limit';
                return false;
            }
            if ( $counters['retained'] >= $maxSelectors || count($result['rules']) >= $maxRules ) {
                $result['truncated'] = true;
                $result['diagnostics'][] = 'css_rule_or_selector_limit';
                $index = $entry['block_end'];
                continue;
            }
            ++$counters['retained'];
            $result['rules'][] = array(
                'selector' => $entry['selector'],
                'parsed_selector' => $entry['parsed_selector'],
                'declarations' => $entry['declarations'],
                'condition' => $entry['condition'],
                'layer' => $entry['layer'],
                'path' => $entry['path'],
                'hash' => $entry['hash'],
                'order' => $counters['order']++,
                'specificity' => $entry['specificity'],
            );
            ++$index;
        }
        return true;
    }

    /** @param array<string, mixed> $sheet */
    private function linkCondition(array $sheet): ?array
    {
        // `media="all"` matches every medium, so it gates nothing.
        $media = trim((string) ($sheet['media'] ?? ''));
        return '' === $media || 'all' === strtolower($media) ? null : array( 'kind' => 'media', 'query' => $media );
    }

    /**
     * Name/value pairs of a declaration list (a rule body or a `style`
     * attribute) in source order. `;` splits only at top level, outside
     * strings, comments and functions; a segment without `:` is skipped.
     * Names are trimmed and lowercased, except case-sensitive custom
     * properties; values are trimmed.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function declarationList(string $css): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($css, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                continue;
            }
            $name = trim(substr($declaration, 0, $colon));
            $declarations[] = array( 'name' => str_starts_with($name, '--') ? $name : strtolower($name), 'value' => trim(substr($declaration, $colon + 1)) );
        }
        return $declarations;
    }

    /**
     * Every `--name: value` custom-property declaration in `$css`, in source
     * order with values trimmed. Reads a rule body or a whole stylesheet.
     * Declarations split only at top-level `;`, `{` and `}`, so a value keeps
     * the `;` inside a string or function such as a `data:` URI.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function customProperties(string $css): array
    {
        if ( ! str_contains($css, '--') ) {
            return array();
        }
        $properties = array();
        foreach ( CssValueSplitter::splitTopLevel(CssSyntaxScanner::withoutComments($css), array( ';', '{', '}' )) as $declaration ) {
            if ( 1 === preg_match('/^(--[A-Za-z0-9_-]+)\s*:\s*(.*)$/s', $declaration, $match) && '' !== trim($match[2]) ) {
                $properties[] = array( 'name' => $match[1], 'value' => trim($match[2]) );
            }
        }
        return $properties;
    }

    /**
     * The declarations of `$body` named in `$properties`, values with
     * whitespace collapsed. Properties may include '*' for every declaration
     * and '--*' for every custom property. An unterminated string, comment or
     * function makes the whole list unreadable.
     *
     * @param list<string> $properties
     * @return list<array{name: string, value: string}>
     */
    public static function declarations(string $body, array $properties): array
    {
        if ( ! self::isComplete($body) ) {
            return array();
        }
        $all = in_array('*', $properties, true);
        $custom = in_array('--*', $properties, true);
        $declarations = array();
        foreach ( self::declarationList($body) as [ 'name' => $name, 'value' => $value ] ) {
            if ( '' !== $value && ( $all || in_array($name, $properties, true) || ( $custom && str_starts_with($name, '--') ) ) ) {
                $declarations[] = array( 'name' => $name, 'value' => preg_replace('/\s+/', ' ', $value) ?? $value );
            }
        }
        return $declarations;
    }

    private static function isComplete(string $css): bool
    {
        $state = CssSyntaxScanner::state();
        for ( $offset = 0, $length = strlen($css); $offset < $length; ) {
            $offset = CssSyntaxScanner::inertEnd($css, $offset, $state);
            if ( $offset >= $length ) break;
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return false;
            }
            $offset = $next;
        }
        return CssSyntaxScanner::isComplete($state);
    }
}

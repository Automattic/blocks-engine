<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Lossless, rule-local selector factoring at the projection serialization boundary. */
final class CssSelectorListCompactor
{
    private const PSEUDO_ELEMENT = '/::|:(?:before|after|first-line|first-letter)\\b/i';

    /** @var array<string, string> Bounded, document-independent serialization cache. */
    private static array $stylesheets = array();
    private static int $cachedBytes = 0;

    /**
     * @param bool $markerLists Also merge arms that differ only by one
     *     page-local marker class in a `:where()` into `:where(.m1,.m2,…)`.
     *     Used for the site-wide sheet, where routes contribute marker arms.
     */
    public static function stylesheet(string $css, bool $markerLists = false): string
    {
        $key = hash('sha256', $css) . ($markerLists ? ':m' : '');
        if ( isset(self::$stylesheets[ $key ]) ) {
            return self::$stylesheets[ $key ];
        }
        $result = self::compactStylesheet($css, $markerLists);
        if ( count(self::$stylesheets) >= 128 || self::$cachedBytes + strlen($result) > 2097152 ) {
            self::$stylesheets = array();
            self::$cachedBytes = 0;
        }
        if ( strlen($result) <= 2097152 ) {
            self::$stylesheets[ $key ] = $result;
            self::$cachedBytes += strlen($result);
        }
        return $result;
    }

    private static function compactStylesheet(string $css, bool $markerLists = false): string
    {
        if ( ! str_contains($css, ',') || preg_match('/@namespace\b/i', $css) ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transform($css, static fn(string $prelude): string => self::compact($markerLists ? self::mergeMarkerLists($prelude) : $prelude));
    }

    /**
     * Merge arms identical but for the page-local marker inside one
     * top-level `:where(marker)`: `X:where(.m1)Y,X:where(.m2)Y` becomes
     * `X:where(.m1,.m2)Y`. A marker is a plain class, or a RichText carrier
     * (`mark[style*=…]`, `mark[style$=…]`, `span[data-…-marker=…]`) whose
     * lists never mix with class lists. `:where()` weighs nothing and matches
     * when any argument does, so the list is equivalent, also when Y ends in
     * a pseudo-element: `X:where(.m1,.m2)::before` generates exactly the
     * boxes `X:where(.m1)::before,X:where(.m2)::before` do. expand() splits
     * such lists back into arms.
     */
    public static function mergeMarkerLists(string $prelude): string
    {
        if ( ! str_contains($prelude, ',') || ! preg_match(self::PAGE_MARKER, $prelude) ) {
            return $prelude;
        }
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $arms || count($arms) < 2 ) {
            return $prelude;
        }
        $groups = array();
        $order = array();
        foreach ( $arms as $index => $arm ) {
            $arm = trim($arm);
            $key = "\1" . $index;
            $markers = array();
            $list = self::markerList($arm);
            if ( null !== $list ) {
                [$kind, $prefix, $markers, $suffix] = $list;
                $key = $kind . "\0" . $prefix . "\0" . $suffix;
            }
            if ( ! isset($groups[ $key ]) ) {
                $order[] = $key;
                $groups[ $key ] = array('arm' => $arm, 'markers' => array());
            }
            foreach ( $markers as $marker ) {
                $groups[ $key ]['markers'][ $marker ] = true;
            }
        }
        if ( count($order) === count($arms) ) {
            return $prelude;
        }
        $output = array();
        foreach ( $order as $key ) {
            $group = $groups[ $key ];
            if ( array() === $group['markers'] ) {
                $output[] = $group['arm'];
                continue;
            }
            [, $prefix, $suffix] = explode("\0", $key, 3);
            $output[] = $prefix . ':where(' . implode(',', array_keys($group['markers'])) . ')' . $suffix;
        }
        return implode(',', $output);
    }

    /** One RichText carrier selector of a page-local marker. */
    private const RICHTEXT_CARRIER = '(?:mark\[style\*="--blocks-engine-richtext-marker:blocks-engine-richtext-[a-f0-9]{12}-[0-9]+;"\]|mark\[style\$="--blocks-engine-richtext-marker:blocks-engine-richtext-[a-f0-9]{12}-[0-9]+"\]|span\[data-blocks-engine-richtext-marker="blocks-engine-richtext-[a-f0-9]{12}-[0-9]+"\])';

    /**
     * The first top-level `:where()` of an arm listing only page-local
     * markers of one kind, as [kind, prefix, markers, suffix]. Quotes are
     * accepted only inside RichText carriers, so parentheses outside them
     * count as selector nesting.
     *
     * @return array{0:string,1:string,2:list<string>,3:string}|null
     */
    private static function markerList(string $arm): ?array
    {
        if ( str_contains($arm, '/*') || str_contains($arm, '\\') ) {
            return null;
        }
        $kind = 'c';
        $pattern = self::MARKER_WHERE;
        if ( false !== strpbrk($arm, '"\'') ) {
            if ( false !== strpbrk((string) preg_replace('/' . self::RICHTEXT_CARRIER . '/', '', $arm), '"\'') ) {
                return null;
            }
            $kind = 'r';
            $pattern = '/:where\(((?:' . self::RICHTEXT_CARRIER . ',)*' . self::RICHTEXT_CARRIER . ')\)/';
        }
        if ( ! preg_match_all($pattern, $arm, $matches, PREG_OFFSET_CAPTURE) ) {
            return null;
        }
        foreach ( $matches[0] as $index => [$text, $offset] ) {
            $before = substr($arm, 0, $offset);
            if ( substr_count($before, '(') !== substr_count($before, ')') ) {
                continue;
            }
            return array($kind, $before, explode(',', $matches[1][ $index ][0]), substr($arm, $offset + strlen($text)));
        }
        return null;
    }

    /** A `:where()` whose arguments are page-local marker classes only. */
    private const MARKER_WHERE = '/:where\(((?:\.blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+,)*\.blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+)\)/';

    /**
     * Inverse of compact() for page-local marker lists: expand every whole
     * compound `:is(m1,m2,…)` whose alternatives share one specificity in the
     * strict grammar, and at least one names a page-local block marker, back
     * into one arm per alternative. Equal specificity and strict alternatives
     * make the arm list cascade-equivalent to the packed form, so two pages'
     * projections of one source rule align arm by arm before reconciliation.
     */
    public static function expand(string $prelude): string
    {
        if ( ! preg_match(self::PAGE_MARKER, $prelude) ) {
            return $prelude;
        }
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $arms ) {
            return $prelude;
        }
        $output = array();
        $changed = false;
        while ( array() !== $arms ) {
            $arm = trim((string) array_shift($arms));
            $expanded = self::expandArm($arm);
            if ( null === $expanded ) {
                $output[] = $arm;
                continue;
            }
            $changed = true;
            array_unshift($arms, ...$expanded);
        }
        return $changed ? implode(',', $output) : $prelude;
    }

    /** Page-local block marker class (`blocks-engine-<kind>-<hash>-<n>`). */
    public const PAGE_MARKER = '/(?<![a-zA-Z0-9_-])blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+(?![a-zA-Z0-9_-])/';

    /** @return list<string>|null */
    private static function expandArm(string $arm): ?array
    {
        // Richtext carriers use style attributes rather than marker classes.
        $identity = 'blocks-engine-richtext-[a-f0-9]{12}-[0-9]+';
        $richtext = '(?:mark\\[style\\*="--blocks-engine-richtext-marker:' . $identity . ';"\\]|mark\\[style\\$="--blocks-engine-richtext-marker:' . $identity . '"\\]|span\\[data-blocks-engine-richtext-marker="' . $identity . '"\\])';
        if ( ! str_contains($arm, '\\') && preg_match_all('/:where\\(((?:' . $richtext . ',)+' . $richtext . ')\\)/', $arm, $lists, PREG_OFFSET_CAPTURE) ) {
            foreach ( $lists[0] as $index => [$text, $offset] ) {
                $before = substr($arm, 0, $offset);
                if ( substr_count($before, '(') !== substr_count($before, ')') ) continue;
                $suffix = substr($arm, $offset + strlen($text));
                return array_map(static fn(string $marker): string => $before . ':where(' . $marker . ')' . $suffix, explode(',', $lists[1][$index][0]));
            }
        }
        if ( false === strpbrk($arm, '\\"\'') && preg_match_all(self::MARKER_WHERE, $arm, $matches, PREG_OFFSET_CAPTURE) ) {
            foreach ( $matches[0] as $index => [$text, $offset] ) {
                $before = substr($arm, 0, $offset);
                if ( ! str_contains($matches[1][ $index ][0], ',') || substr_count($before, '(') !== substr_count($before, ')') ) {
                    continue;
                }
                $suffix = substr($arm, $offset + strlen($text));
                return array_map(static fn(string $marker): string => $before . ':where(' . $marker . ')' . $suffix, explode(',', $matches[1][ $index ][0]));
            }
        }
        if ( ! str_contains($arm, ':is(') ) {
            return null;
        }
        $tokens = CssSelectorTokenizer::tokenize($arm);
        if ( ! $tokens['supported'] ) {
            return null;
        }
        foreach ( $tokens['compound_spans'] as $position => $span ) {
            $compound = $tokens['compounds'][ $position ];
            if ( ! str_starts_with($compound, ':is(') || ! str_ends_with($compound, ')') || ! preg_match(self::PAGE_MARKER, $compound) ) {
                continue;
            }
            $alternatives = CssStylesheetTransformer::splitSelectorList(substr($compound, 4, -1));
            if ( null === $alternatives || count($alternatives) < 2 ) {
                continue;
            }
            $weights = array();
            foreach ( $alternatives as $alternative ) {
                $weights[ (string) self::plainCompoundWeight(trim($alternative)) ] = true;
            }
            if ( 1 !== count($weights) || isset($weights['']) ) {
                continue;
            }
            $prefix = substr($arm, 0, $span['start']);
            $suffix = substr($arm, $span['end']);
            return array_map(static fn(string $alternative): string => $prefix . trim($alternative) . $suffix, $alternatives);
        }
        return null;
    }

    /**
     * Merge arms identical but for `:nth-child(k)` at one position, for every
     * k from 1 to b, into one `:nth-child(-n+b)` arm. An element matches the
     * merged arm exactly when it matches one of them, with the same
     * specificity, so the list stays equivalent whatever the sibling count.
     */
    public static function mergeNthChildRuns(string $prelude): string
    {
        if ( ! str_contains($prelude, ':nth-child(') || ! str_contains($prelude, ',') ) {
            return $prelude;
        }
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $arms || count($arms) < 2 ) {
            return $prelude;
        }
        $arms = array_map('trim', $arms);
        $changed = false;
        do {
            $runs = array();
            foreach ( $arms as $index => $arm ) {
                if ( str_contains($arm, '/*') || false !== strpbrk($arm, '\\"\'') || ! preg_match_all('/:nth-child\((\d+)\)/', $arm, $matches, PREG_OFFSET_CAPTURE) ) {
                    continue;
                }
                foreach ( $matches[0] as $position => [$text, $offset] ) {
                    $key = substr($arm, 0, $offset) . "\0" . substr($arm, $offset + strlen($text));
                    $runs[ $key ][ (int) $matches[1][ $position ][0] ] ??= $index;
                }
            }
            $merged = false;
            foreach ( $runs as $key => $members ) {
                $last = 0;
                while ( isset($members[ $last + 1 ]) ) {
                    ++$last;
                }
                if ( $last < 2 ) {
                    continue;
                }
                [$before, $after] = explode("\0", $key, 2);
                $first = PHP_INT_MAX;
                foreach ( range(1, $last) as $k ) {
                    $first = min($first, $members[ $k ]);
                    unset($arms[ $members[ $k ] ]);
                }
                // The merged arm takes the place of the run's earliest member.
                $arms[ $first ] = $before . ':nth-child(-n+' . $last . ')' . $after;
                ksort($arms);
                $merged = $changed = true;
                break;
            }
        } while ( $merged );
        return $changed ? implode(',', $arms) : $prelude;
    }

    /**
     * The stylesheet with every style rule prelude passed through
     * withoutRedundantSelectorWhitespace(). Declaration blocks, at-rule
     * preludes and comments are kept byte for byte.
     */
    public static function withoutRedundantSelectorWhitespaceInStylesheet(string $css): string
    {
        if ( ! preg_match('/\s[,>+~]|[,>+~]\s/', $css) ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transformStyleRules($css, static fn(string $prelude, string $body): string => self::withoutRedundantSelectorWhitespace($prelude) . '{' . $body . '}');
    }

    /**
     * The selector list without top-level whitespace that separates no
     * tokens: none beside a `,`, `>`, `+` or `~` combinator or at either
     * end, and every other run collapsed to one space (a descendant
     * combinator). Strings, comments, escapes (including an escape's
     * terminating space), attribute brackets and functional pseudo-class
     * arguments are kept byte for byte, so `[class~="a b"]`, `.a\ b` and
     * `:has(> img)` are unchanged. Every arm matches the same elements with
     * the same specificity. A malformed selector is returned unchanged.
     */
    public static function withoutRedundantSelectorWhitespace(string $prelude): string
    {
        $state     = CssSyntaxScanner::state();
        $output    = '';
        $length    = strlen($prelude);
        $pending   = false;
        $delimiter = ',';
        for ( $offset = 0; $offset < $length; ) {
            $character = $prelude[ $offset ];
            $inert = $state['comment'] || $state['url'] || '' !== $state['quote'] || 0 < $state['brackets'] || 0 < $state['parens'];
            if ( ! $inert && CssSyntaxScanner::isCssWhitespace($character) ) {
                $pending = true;
                ++$offset;
                continue;
            }
            if ( $pending && ! in_array($delimiter, array( ',', '>', '+', '~' ), true) && ! in_array($character, array( ',', '>', '+', '~' ), true) ) {
                $output .= ' ';
            }
            $pending = false;
            $next = CssSyntaxScanner::consume($prelude, $offset, $state);
            if ( null === $next ) {
                return $prelude;
            }
            $delimiter = ! $inert && $next === $offset + 1 && '' === $state['quote'] && ! $state['comment'] && in_array($character, array( ',', '>', '+', '~' ), true) ? $character : '';
            $output .= substr($prelude, $offset, $next - $offset);
            $offset = $next;
        }
        return CssSyntaxScanner::isComplete($state) && ! $state['comment'] && '' === $state['quote'] ? $output : $prelude;
    }

    /**
     * The stylesheet with every selector arm dropped that another arm of the
     * same rule already covers. See withoutSubsumedArms().
     */
    public static function withoutSubsumedArmsInStylesheet(string $css): string
    {
        if ( ! str_contains($css, ',') || ! str_contains($css, ':') ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transformStyleRules($css, static fn(string $prelude, string $body): string => self::withoutSubsumedArms($prelude) . '{' . $body . '}');
    }

    /**
     * Drop each arm that matches a subset of another arm of the same list
     * with the same specificity. Projection restates a source arm with
     * zero-specificity restrictions (`.btn:not(:where(.role))`,
     * `:where(.marker)` beside `*`), and folding repeated declaration blocks
     * puts the restatement in one list with the original. Removing every
     * top-level `:where(…)` and `:not(:where(…))` from such an arm only
     * widens what it matches and leaves its specificity unchanged, so when
     * that relaxed arm is itself in the list, every element the restricted
     * arm matches is matched by the other arm with the same weight, in the
     * same rule: the rule applies exactly as before. Validity is unchanged
     * too, since `:where()` is forgiving. Arms with comments, escapes,
     * namespaces or nesting are left alone.
     */
    public static function withoutSubsumedArms(string $prelude): string
    {
        if ( ! str_contains($prelude, ',') || ! str_contains($prelude, ':where(') ) {
            return $prelude;
        }
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $arms || count($arms) < 2 ) {
            return $prelude;
        }
        $present = array();
        foreach ( $arms as $arm ) {
            $canonical = self::canonicalArm(trim($arm));
            if ( null !== $canonical ) {
                $present[ $canonical ] = true;
            }
        }
        $kept = array();
        foreach ( $arms as $arm ) {
            $relaxed = self::relaxedArm(trim($arm));
            $relaxed = null === $relaxed ? null : self::canonicalArm($relaxed);
            if ( null !== $relaxed && isset($present[ $relaxed ]) && $relaxed !== self::canonicalArm(trim($arm)) ) {
                continue;
            }
            $kept[] = $arm;
        }
        if ( count($kept) === count($arms) || array() === $kept ) {
            return $prelude;
        }
        // Comments ahead of the first arm (licenses) stay ahead of the rule.
        if ( $kept[0] !== $arms[0] && preg_match('#^(?:\s*/\*.*?\*/)+\s*#s', $arms[0], $leading) ) {
            $kept[0] = $leading[0] . ltrim($kept[0]);
        }
        return implode(',', $kept);
    }

    /** The arm without its top-level `:where(…)` and `:not(:where(…))`, or null when it cannot be read safely. */
    private static function relaxedArm(string $arm): ?string
    {
        if ( '' === $arm || str_contains($arm, '/*') || false !== strpbrk($arm, '\\|&') ) {
            return null;
        }
        $out = '';
        $pseudoElement = false;
        for ( $i = 0, $length = strlen($arm); $i < $length; ++$i ) {
            $char = $arm[ $i ];
            if ( '[' === $char || '(' === $char ) {
                $close = CssSyntaxScanner::matchingClose($arm, $i);
                if ( null === $close ) {
                    return null;
                }
                $out .= substr($arm, $i, $close - $i + 1);
                $i = $close;
                continue;
            }
            // Nothing after a pseudo-element is relaxed: a `:where()` there
            // could be what makes a browser reject the whole list.
            if ( ':' === $char && ( ':' === ($arm[ $i + 1 ] ?? '') || preg_match('/\G:(?:before|after|first-line|first-letter)\b/i', $arm, $match, 0, $i) ) ) {
                $pseudoElement = true;
            }
            if ( ':' === $char && ! $pseudoElement ) {
                $piece = null;
                if ( 0 === substr_compare($arm, ':where(', $i, 7, true) ) {
                    $close = CssSyntaxScanner::matchingClose($arm, $i + 6);
                    $piece = null === $close ? null : $close;
                } elseif ( 0 === substr_compare($arm, ':not(:where(', $i, 12, true) ) {
                    $inner = CssSyntaxScanner::matchingClose($arm, $i + 11);
                    $close = null === $inner ? null : CssSyntaxScanner::matchingClose($arm, $i + 4);
                    $piece = null !== $close && $close === $inner + 1 ? $close : null;
                }
                if ( null !== $piece ) {
                    // A compound left without a simple selector is `*`.
                    if ( '' === $out || false !== strpos(" \t\n\r\f>+~", substr($out, -1)) ) {
                        $out .= '*';
                    }
                    $i = $piece;
                    continue;
                }
            }
            $out .= $char;
        }
        return $out;
    }

    /**
     * The arm with normalized combinator whitespace and without a `*` that
     * other simple selectors in its compound make implicit, or null when it
     * cannot be read safely.
     */
    private static function canonicalArm(string $arm): ?string
    {
        if ( '' === $arm || str_contains($arm, '/*') || false !== strpbrk($arm, '\\|&') ) {
            return null;
        }
        $out = '';
        $combinator = null;
        for ( $i = 0, $length = strlen($arm); $i < $length; ++$i ) {
            $char = $arm[ $i ];
            if ( false !== strpos(" \t\n\r\f", $char) ) {
                $combinator ??= ' ';
                continue;
            }
            if ( false !== strpos('>+~', $char) ) {
                $combinator = $char;
                continue;
            }
            if ( null !== $combinator ) {
                $out .= $combinator;
                $combinator = null;
            }
            if ( '[' === $char || '(' === $char ) {
                $close = CssSyntaxScanner::matchingClose($arm, $i);
                if ( null === $close ) {
                    return null;
                }
                $out .= substr($arm, $i, $close - $i + 1);
                $i = $close;
                continue;
            }
            if ( '*' === $char && isset($arm[ $i + 1 ]) && false !== strpos('.#:[', $arm[ $i + 1 ]) ) {
                continue;
            }
            $out .= $char;
        }
        return null === $combinator ? $out : null;
    }

    /**
     * The arms compact() factored into one `:is()` compound, split back out:
     * `X :is(.a,.b) Y` becomes `X .a Y` and `X .b Y`. Only a list of plain
     * compounds with one weight is split, the lists compact() writes, so
     * every arm keeps the specificity it had inside the list. A condition
     * appended to the list compound later (`:is(.a,.b):where(:root.r)`)
     * follows each arm. A selector without such a list is its own only arm.
     *
     * @return list<string>
     */
    public static function factoredArms(string $selector, int $limit = 64): array
    {
        $selector = trim($selector);
        if ( ! str_contains($selector, ':is(') ) {
            return array($selector);
        }
        $tokens = CssSelectorTokenizer::tokenize($selector);
        if ( ! $tokens['supported'] ) {
            return array($selector);
        }
        foreach ( $tokens['compound_spans'] as $position => $span ) {
            $compound = $tokens['compounds'][ $position ];
            if ( ! str_starts_with($compound, ':is(') ) {
                continue;
            }
            $close = CssSyntaxScanner::matchingClose($compound, 3);
            if ( null === $close ) {
                continue;
            }
            $alternatives = CssStylesheetTransformer::splitSelectorList(substr($compound, 4, $close - 4));
            if ( null === $alternatives || count($alternatives) < 2 ) {
                continue;
            }
            $weights = array();
            foreach ( $alternatives as $alternative ) {
                $weights[ (string) self::plainCompoundWeight(trim($alternative)) ] = true;
            }
            if ( 1 !== count($weights) || isset($weights['']) ) {
                continue;
            }
            $prefix = substr($selector, 0, $span['start']);
            $suffix = substr($compound, $close + 1) . substr($selector, $span['end']);
            $arms = array();
            foreach ( $alternatives as $alternative ) {
                foreach ( self::factoredArms($prefix . trim($alternative) . $suffix, $limit) as $arm ) {
                    $arms[] = $arm;
                    if ( count($arms) > $limit ) {
                        return array($selector);
                    }
                }
            }
            return $arms;
        }
        return array($selector);
    }

    public static function compact(string $prelude): string
    {
        if ( ! str_contains($prelude, ',') ) {
            return $prelude;
        }
        if ( isset(self::$compacted[ $prelude ]) ) {
            return self::$compacted[ $prelude ];
        }
        $result = self::compactUncached($prelude);
        if ( count(self::$compacted) >= 4096 || self::$compactedBytes + strlen($prelude) + strlen($result) > 8388608 ) {
            self::$compacted = array();
            self::$compactedBytes = 0;
        }
        self::$compacted[ $prelude ] = $result;
        self::$compactedBytes += strlen($prelude) + strlen($result);
        return $result;
    }

    private static function compactUncached(string $prelude): string
    {
        // Projection may already have wrapped per-node alternatives in :is().
        // Normalize those lists too: identical projected markers otherwise grow
        // with every occurrence of a repeated component on the page.
        $prelude = self::compactNestedLists($prelude);
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if (null !== $arms) $prelude = implode(',', array_unique(array_map('trim', $arms)));
        $prelude = self::mergeNthChildRuns($prelude);
        if ( ! str_contains($prelude, ',') ) {
            return $prelude;
        }
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $arms || count($arms) < 2 ) {
            return $prelude;
        }
        $groups = array();
        foreach ( $arms as $index => $arm ) {
            $arm = trim($arm);
            $tokens = CssSelectorTokenizer::tokenize($arm);
            if ( ! $tokens['supported'] ) {
                continue;
            }
            foreach ( $tokens['compound_spans'] as $position => $span ) {
                $compound = $tokens['compounds'][ $position ];
                // A pseudo-element (legacy single-colon forms included) never
                // goes in :is(); the compounds before it may, and it stays in
                // the shared suffix: `:is(.a,.b) .x::before`.
                if ( preg_match(self::PSEUDO_ELEMENT, $compound) ) {
                    continue;
                }
                $weight = self::plainCompoundWeight($compound);
                if ( null === $weight ) {
                    continue;
                }
                $prefix = substr($arm, 0, $span['start']);
                $suffix = substr($arm, $span['end']);
                $key = $prefix . "\0" . $suffix . "\0" . $weight;
                $groups[ $key ][] = array($index, $compound, $prefix, $suffix);
            }
        }
        $used = array();
        $replacements = array();
        foreach ( $groups as $group ) {
            $available = array();
            foreach ( $group as $entry ) {
                if ( ! isset($used[ $entry[0] ]) ) {
                    $available[] = $entry;
                }
            }
            if ( count($available) < 2 ) {
                continue;
            }
            $first = $available[0];
            $merged = $first[2] . ':is(' . implode(',', array_unique(array_column($available, 1))) . ')' . $first[3];
            $originalBytes = count($available) - 1;
            foreach ( $available as $entry ) {
                $originalBytes += strlen($arms[ $entry[0] ]);
            }
            if ( strlen($merged) >= $originalBytes ) {
                continue;
            }
            // All arms belong to ONE rule: even nonadjacent arms may be
            // grouped without crossing a cascade boundary. Never merge rules.
            $replacements[ $first[0] ] = $merged;
            foreach ( $available as $entry ) {
                $used[ $entry[0] ] = true;
            }
        }
        if ( array() === $replacements ) {
            return $prelude;
        }
        $output = array();
        foreach ( $arms as $index => $arm ) {
            if ( isset($replacements[ $index ]) ) {
                $output[] = $replacements[ $index ];
            } elseif ( ! isset($used[ $index ]) ) {
                $output[] = $arm;
            }
        }
        return implode(',', $output);
    }

    /**
     * Bounded memo of compact() by prelude. Projection compacts one rule's
     * selector list per projected rule, and a page restates the same lists
     * across rules and passes; the result depends on the text alone.
     *
     * @var array<string, string>
     */
    private static array $compacted = array();

    private static int $compactedBytes = 0;

    private static int $nestedListScans = 0;

    /**
     * Selector bytes compactNestedLists() has scanned since process start;
     * observable for tests. A list is scanned once per distinct prelude, and
     * each scan is linear in the list's length (#6752).
     */
    public static function nestedListScanBytes(): int
    {
        return self::$nestedListScans;
    }

    private static function compactNestedLists(string $selector): string
    {
        // Do not interpret punctuation inside comments or quoted syntax.
        // Attribute strings are skipped as a whole below.
        if (str_contains($selector, '/*') || ! str_contains($selector, '(')) return $selector;
        $out = '';
        $length = strlen($selector);
        self::$nestedListScans += $length;
        for ($i = 0; $i < $length; ) {
            // Copy plain runs whole: only escapes, brackets and parentheses
            // need reading.
            $run = strcspn($selector, '\\[(', $i);
            if ($run > 0) {
                $out .= substr($selector, $i, $run);
                $i += $run;
                if ($i >= $length) break;
            }
            $char = $selector[$i];
            if ('\\' === $char) {
                $out .= substr($selector, $i, 2);
                $i += 2;
                continue;
            }
            $close = CssSyntaxScanner::matchingClose($selector, $i);
            if (null === $close) return $selector;
            $inner = substr($selector, $i + 1, $close - $i - 1);
            // Only the output's tail can name the pseudo-class, so test that
            // tail rather than the whole output (which made the scan
            // quadratic in the list length).
            if ('(' === $char && preg_match('/:(?:is|where|not|has)$/i', substr($out, -8))) {
                $inner = self::compact($inner);
            }
            $out .= $char . $inner . $selector[$close];
            $i = $close + 1;
        }
        return $out;
    }

    /** @var array<string, string|null> Bounded syntax-only cache; never holds document state. */
    private static array $weights = array();

    private static function plainCompoundWeight(string $compound): ?string
    {
        if ( array_key_exists($compound, self::$weights) ) {
            return self::$weights[ $compound ];
        }
        if ( count(self::$weights) >= 4096 ) {
            self::$weights = array();
        }
        return self::$weights[ $compound ] = self::computeCompoundWeight($compound);
    }

    private static function computeCompoundWeight(string $compound): ?string
    {
        // Deliberately a small, universally valid grammar. Unknown
        // pseudos, namespaces, escapes, attributes and nesting are NOT moved
        // into a forgiving list: doing so could turn an invalid rule into a
        // matching one. Shared context remains outside :is() byte-for-byte.
        $identifier = '(?:[a-zA-Z_][a-zA-Z0-9_-]*|-[a-zA-Z_][a-zA-Z0-9_-]*|--[a-zA-Z0-9_-]+)';
        if ( '' === $compound ) {
            return null;
        }
        if ( ! preg_match('/^(?:(' . $identifier . '|\*))?((?:[.#]' . $identifier . ')*)$/D', $compound, $match) ) {
            // Single-argument logical pseudos over the same strict grammar
            // are valid in every supported target, including specificity shims.
            // No forgiving alternatives or unknown pseudo syntax are admitted.
            $grammar = '~^(?&compound)$(?(DEFINE)(?<compound>(?:' . $identifier . '|\\*)?(?:(?:[.#]' . $identifier . ')|:(?:where|is|not)\\((?&compound)\\))+))~D';
            if ( ! preg_match($grammar, $compound) ) {
                return null;
            }
            $parsed = CssSelectorMatcher::parse($compound);
            return $parsed['supported'] ? implode(':', CssSelectorMatcher::specificityCounts($parsed)) : null;
        }
        $simples = $match[2] ?? '';
        return substr_count($simples, '#') . ':' . substr_count($simples, '.') . ':' . (isset($match[1]) && '' !== $match[1] && '*' !== $match[1] ? '1' : '0');
    }
}

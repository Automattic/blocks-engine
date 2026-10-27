<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Lossless, rule-local selector factoring at the projection serialization boundary. */
final class CssSelectorListCompactor
{
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
     * Merge arms identical but for the page-local marker class inside one
     * top-level `:where(.marker)`: `X:where(.m1)Y,X:where(.m2)Y` becomes
     * `X:where(.m1,.m2)Y`. `:where()` weighs nothing and matches when any
     * argument does, and every argument is a plain class, so the list is
     * equivalent. expand() splits such lists back into arms.
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
            if ( ! preg_match('/::|:(?:before|after|first-line|first-letter)\b/i', $arm) && ! str_contains($arm, '/*') && false === strpbrk($arm, '\\"\'') && preg_match_all(self::MARKER_WHERE, $arm, $matches, PREG_OFFSET_CAPTURE) ) {
                foreach ( $matches[0] as $index2 => [$text, $offset] ) {
                    $before = substr($arm, 0, $offset);
                    if ( substr_count($before, '(') !== substr_count($before, ')') ) {
                        continue;
                    }
                    $key = substr($arm, 0, $offset) . "\0" . substr($arm, $offset + strlen($text));
                    $markers = explode(',', $matches[1][ $index2 ][0]);
                    break;
                }
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
            [$prefix, $suffix] = explode("\0", $key, 2);
            $output[] = $prefix . ':where(' . implode(',', array_keys($group['markers'])) . ')' . $suffix;
        }
        return implode(',', $output);
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

    public static function compact(string $prelude): string
    {
        if ( ! str_contains($prelude, ',') ) {
            return $prelude;
        }
        $arms = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $arms || count($arms) < 2 ) {
            return $prelude;
        }
        $groups = array();
        foreach ( $arms as $index => $arm ) {
            // Include legacy single-colon pseudo-elements. Never put a
            // pseudo-element in :is(), even when it occurs in a shared part.
            if ( preg_match('/::|:(?:before|after|first-line|first-letter)\b/i', $arm) ) {
                continue;
            }
            $arm = trim($arm);
            $tokens = CssSelectorTokenizer::tokenize($arm);
            if ( ! $tokens['supported'] ) {
                continue;
            }
            foreach ( $tokens['compound_spans'] as $position => $span ) {
                $compound = $tokens['compounds'][ $position ];
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

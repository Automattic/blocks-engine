<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Lossless, rule-local selector factoring at the projection serialization boundary. */
final class CssSelectorListCompactor
{
    /** @var array<string, string> Bounded, document-independent serialization cache. */
    private static array $stylesheets = array();
    private static int $cachedBytes = 0;

    public static function stylesheet(string $css): string
    {
        $key = hash('sha256', $css);
        if ( isset(self::$stylesheets[ $key ]) ) {
            return self::$stylesheets[ $key ];
        }
        $result = self::compactStylesheet($css);
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

    private static function compactStylesheet(string $css): string
    {
        if ( ! str_contains($css, ',') || preg_match('/@namespace\b/i', $css) ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transform($css, static fn(string $prelude): string => self::compact($prelude));
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

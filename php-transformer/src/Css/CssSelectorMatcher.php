<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use DOMElement;
use DOMNode;

/** Parses a conservative CSS selector subset and matches it without mutating a DOM. */
final class CssSelectorMatcher
{
    /**
     * HTML defines these enumerated attribute values as ASCII-case-insensitive
     * by default, which this matcher does not model.
     *
     * Keyed for O(1) membership because every selector match that misses the
     * cache tests it, and a page can miss hundreds of thousands of times. As a
     * class constant it is built once at compile time rather than rebuilt on
     * each of those calls.
     */
    private const ENUMERATED_ATTRIBUTES = array(
        'autocomplete' => true,
        'contenteditable' => true,
        'dir' => true,
        'draggable' => true,
        'enterkeyhint' => true,
        'hidden' => true,
        'inputmode' => true,
        'kind' => true,
        'method' => true,
        'rel' => true,
        'spellcheck' => true,
        'translate' => true,
        'type' => true,
    );

    /**
     * @return array{supported: bool, reason: string|null, compounds: list<array<string, mixed>>, combinators: list<string>, type_spans: list<array{start: int, end: int, name: string, compound: int}>, rightmost_compound_span: array{start: int, end: int}|null, pseudo_state_suffix_span: array{start: int, end: int}|null, rightmost_rewrite_end: int|null}
     */
    public static function parse(string $selector): array
	{
		static $cache = array();
		if ( array_key_exists($selector, $cache) ) {
			return $cache[$selector];
		}
		$parsed = self::parseUncached($selector);
		if ( count($cache) < 10000 ) {
			$cache[$selector] = $parsed;
		}
		return $parsed;
	}

	/** @return array<string, mixed> */
	private static function parseUncached(string $selector): array
    {
        if ( 1 !== preg_match('//u', $selector) ) {
            return self::unsupported('invalid-utf8');
        }

        $unwrapped = self::parseWhollyZeroedSelector($selector);
        if ( null !== $unwrapped ) {
            return $unwrapped;
        }

        $tokens = CssSelectorTokenizer::tokenize($selector);
        if ( ! $tokens['supported'] ) {
            return self::unsupported('tokenization');
        }

        $compounds = array();
        $typeSpans = array();
        $suffix = null;
        $lastCompound = count($tokens['compounds']) - 1;
        foreach ( $tokens['compounds'] as $index => $compound ) {
            $parsed = self::parseCompound($compound, $tokens['compound_spans'][ $index ]['start'], $index === $lastCompound);
            if ( null === $parsed ) {
                return self::unsupported('unsupported-selector');
            }
            $compounds[] = $parsed['compound'];
            if ( null !== $parsed['type_span'] ) {
                $typeSpans[] = array_merge($parsed['type_span'], array( 'compound' => $index ));
            }
            if ( null !== $parsed['suffix'] ) {
                $suffix = $parsed['suffix'];
            }
        }
        if ( in_array('||', $tokens['combinators'], true) ) {
            return self::unsupported('column-combinator');
        }

        $rightmost = $tokens['compound_spans'][ $lastCompound ];
        return array(
            'supported' => true,
            'reason' => null,
            'compounds' => $compounds,
            'combinators' => $tokens['combinators'],
            'type_spans' => $typeSpans,
            'rightmost_compound_span' => $rightmost,
            'pseudo_state_suffix_span' => $suffix,
            'rightmost_rewrite_end' => $suffix['start'] ?? $rightmost['end'],
        );
    }

    /** @param array<string, mixed> $selector */
    public static function specificity(array $selector): int
    {
        if ( ! ($selector['supported'] ?? false) ) {
            return 0;
        }

        $specificity = 0;
        foreach ( $selector['compounds'] as $compound ) {
            $specificity += self::compoundSpecificity($compound);
        }
        if ( null !== $selector['pseudo_state_suffix_span'] ) {
            $specificity += 10;
        }
        return $specificity;
    }

    /**
     * Specificity of one compound, discounting everything `:where()` contributed.
     *
     * `:where()` matches without adding specificity — that is the whole reason
     * the engine emits it when projecting author rules, so the author's own
     * ranking survives the rewrite. The tokenizer already records which simple
     * selectors came from inside it, under `zero_specificity`, and this is the
     * reading that honours it: without the discount `:where(.a.b)` scored 20
     * where CSS says 0, and a projected rule outranked the author rule it was
     * derived from.
     *
     * @param array<string, mixed> $compound
     */
    private static function compoundSpecificity(array $compound): int
    {
        // Everything inside a wholly-wrapping `:where()` scores zero, including
        // the structural pseudo-classes and negations a per-simple-selector
        // discount cannot reach.
        if ( true === ( $compound['forced_zero_specificity'] ?? false ) ) {
            return 0;
        }

        $zero = $compound['zero_specificity'] ?? array();

        $ids = count($compound['ids']) - (int) ( $zero['ids'] ?? 0 );
        $classes = count($compound['classes']) - (int) ( $zero['classes'] ?? 0 )
            + count($compound['attributes']) - (int) ( $zero['attributes'] ?? 0 )
            + ( null !== $compound['nth_child'] ? 1 : 0 )
            + (int) $compound['first_child']
            + (int) $compound['last_child']
            + (int) ( $compound['root'] ?? false )
            + ( $compound['resting_state_negations'] ?? 0 );
        $types = ( null === $compound['type'] ? 0 : 1 ) - (int) ( $zero['types'] ?? 0 );

        $specificity = 100 * $ids + 10 * $classes + $types;
        foreach ( $compound['not'] as $negated ) {
            foreach ( $negated['compounds'] as $negatedCompound ) {
                $specificity += self::compoundSpecificity($negatedCompound);
            }
        }
        foreach ( $compound['any'] ?? array() as $group ) {
            $specificity += $group['specificity'];
        }

        return $specificity;
    }

    /**
     * What a compound's `:is()`/`:where()` selector-list arguments add to its
     * specificity, split into id, class and type counts for callers that
     * rebuild a selector's weight simple selector by simple selector.
     *
     * @param array<string, mixed> $compound
     * @return array{ids: int, classes: int, types: int}
     */
    public static function selectorListArgumentSpecificity(array $compound): array
    {
        $specificity = 0;
        foreach ( $compound['any'] ?? array() as $group ) {
            $specificity += $group['specificity'];
        }

        return array( 'ids' => intdiv($specificity, 100), 'classes' => intdiv($specificity % 100, 10), 'types' => $specificity % 10 );
    }

    /**
     * Parse the selector list inside `:is()` or `:where()`.
     *
     * The compound form above folds a single argument into the surrounding
     * compound, which cannot express "any of". A list keeps each alternative as
     * its own selector: the compound matches when one of them matches the same
     * element, which is exactly how `:is()` and `:where()` evaluate. `:is()`
     * weighs as its most specific alternative and `:where()` as zero. An
     * alternative this matcher cannot read keeps the whole selector
     * unsupported rather than matching on the alternatives it can read.
     *
     * @param list<string> $alternatives
     * @return array{alternatives: list<array{compounds: list<array<string, mixed>>, combinators: list<string>}>, specificity: int}|null
     */
    private static function parseSelectorListArgument(array $alternatives, bool $zeroSpecificity): ?array
    {
        $group = array( 'alternatives' => array(), 'specificity' => 0 );
        foreach ( $alternatives as $alternative ) {
            $alternative = trim($alternative);
            if ( '' === $alternative ) {
                return null;
            }
            $parsed = self::parseUncached($alternative);
            if ( ! ($parsed['supported'] ?? false) || null !== ($parsed['pseudo_state_suffix_span'] ?? null) ) {
                return null;
            }
            $group['alternatives'][] = array( 'compounds' => $parsed['compounds'], 'combinators' => $parsed['combinators'] );
            if ( ! $zeroSpecificity ) {
                $group['specificity'] = max($group['specificity'], self::specificity($parsed));
            }
        }

        return $group;
    }

    /**
     * Match from the rightmost compound. Only hover, focus, active, and visited
     * are detachable dynamic suffixes; callers must explicitly account for them.
     *
     * @param array<string, mixed> $selector Result of parse().
     * @return array{supported: bool, matches: bool}
     */
    public static function matches(DOMElement $element, array $selector, bool $accountForPseudoStateSuffix = false, ?CssSelectorMatchCache $cache = null): array
    {
        if ( ! ($selector['supported'] ?? false) ) {
            return array( 'supported' => false, 'matches' => false );
        }
        if ( null !== ($selector['pseudo_state_suffix_span'] ?? null) && ! $accountForPseudoStateSuffix ) {
            return array( 'supported' => false, 'matches' => false );
        }
        if ( self::hasUnmodeledHtmlAttributeValueSemantics($selector['compounds']) ) {
            return array( 'supported' => false, 'matches' => false );
        }

        return array(
            'supported' => true,
            'matches' => self::matchesAt($element, $selector['compounds'], $selector['combinators'], count($selector['compounds']) - 1, $cache),
        );
    }

    /**
     * A selector that is nothing but `:where(...)` is its argument, scored zero.
     *
     * `parseCompound()` reads an `:is()`/`:where()` argument as a single
     * compound, so a complex one — anything carrying a combinator — makes the
     * whole selector unsupported and invisible to the resolver. Tailwind v4
     * writes every sibling-spacing utility in exactly that shape
     * (`:where(.space-y-8>:not(:last-child))`), and losing it did not merely
     * drop the spacing: the resolver fell back to the preflight `margin:0` and
     * baked that zero inline, where the author's rule could no longer win it.
     *
     * Handling the wholly-wrapped case needs no nested matching. `:where(X)`
     * selects exactly what `X` selects and contributes no specificity, so the
     * argument is parsed on its own and every compound is scored zero. Spans
     * stay measured against the original selector by shifting them past the
     * prefix, so selector rewriting still edits the real text.
     *
     * @return array<string, mixed>|null
     */
    private static function parseWhollyZeroedSelector(string $selector): ?array
    {
        $trimmed = trim($selector);
        if ( 0 !== stripos($trimmed, ':where(') || ! str_ends_with($trimmed, ')') ) {
            return null;
        }

        $prefix = strlen(':where(');
        $depth = 0;
        $length = strlen($trimmed);
        for ( $offset = $prefix - 1; $offset < $length; ++$offset ) {
            $character = $trimmed[ $offset ];
            if ( '(' === $character ) {
                ++$depth;
                continue;
            }
            if ( ')' !== $character ) {
                continue;
            }
            --$depth;
            if ( 0 !== $depth ) {
                continue;
            }
            // The wrapper has to close on the last character. Anything else is a
            // compound such as `:where(.a).b`, which the normal path handles.
            if ( $offset !== $length - 1 ) {
                return null;
            }
            break;
        }
        if ( 0 !== $depth ) {
            return null;
        }

        $inner = substr($trimmed, $prefix, -1);
        if ( '' === trim($inner) ) {
            return null;
        }

        $parsed = self::parseUncached($inner);
        if ( ! ( $parsed['supported'] ?? false ) ) {
            return null;
        }

        $shift = strlen($selector) - strlen(ltrim($selector)) + $prefix;
        foreach ( $parsed['compounds'] as $index => $compound ) {
            $compound['forced_zero_specificity'] = true;
            $parsed['compounds'][ $index ] = $compound;
        }
        foreach ( $parsed['type_spans'] as $index => $span ) {
            $parsed['type_spans'][ $index ]['start'] += $shift;
            $parsed['type_spans'][ $index ]['end'] += $shift;
        }
        foreach ( array( 'rightmost_compound_span', 'pseudo_state_suffix_span' ) as $key ) {
            if ( is_array($parsed[ $key ] ?? null) ) {
                $parsed[ $key ]['start'] += $shift;
                $parsed[ $key ]['end'] += $shift;
            }
        }
        if ( null !== ( $parsed['rightmost_rewrite_end'] ?? null ) ) {
            $parsed['rightmost_rewrite_end'] += $shift;
        }

        return $parsed;
    }

    /** @return array{compound: array<string, mixed>, suffix: array{start: int, end: int}|null, type_span: array{start: int, end: int, name: string}|null}|null */
    private static function parseCompound(string $source, int $sourceStart, bool $isRightmost): ?array
    {
        $compound = array( 'type' => null, 'universal' => false, 'classes' => array(), 'ids' => array(), 'attributes' => array(), 'not' => array(), 'any' => array(), 'nth_child' => null, 'first_child' => false, 'last_child' => false, 'root' => false, 'resting_state_negations' => 0, 'zero_specificity' => array( 'types' => 0, 'classes' => 0, 'ids' => 0, 'attributes' => 0 ) );
        $offset = 0;
        $suffix = null;
        $typeSpan = null;
        $hasSimple = false;
        $length = strlen($source);

        while ( $offset < $length ) {
            self::skipIgnorable($source, $offset);
            if ( $offset >= $length ) {
                break;
            }

            $character = $source[ $offset ];
            if ( ':' === $character ) {
                $start = $offset;
                ++$offset;
                if ( ':' === ($source[ $offset ] ?? '') ) {
                    return null;
                }
                $name = self::identifier($source, $offset);
                if ( null === $name ) {
                    return null;
                }
                $lowerName = strtolower($name);
                if ( in_array($lowerName, array( 'is', 'where' ), true) && '(' === ($source[ $offset ] ?? '') ) {
                    $listClosing = self::matchingParenthesis($source, $offset);
                    $alternatives = null === $listClosing ? null : CssStylesheetTransformer::splitSelectorList(substr($source, $offset + 1, $listClosing - $offset - 1));
                    if ( is_array($alternatives) && count($alternatives) > 1 ) {
                        $group = self::parseSelectorListArgument($alternatives, 'where' === $lowerName);
                        if ( null === $group ) {
                            return null;
                        }
                        $compound['any'][] = $group;
                        $offset = $listClosing + 1;
                        $hasSimple = true;
                        continue;
                    }
                    $closing = strpos($source, ')', $offset + 1);
                    if ( false === $closing ) {
                        return null;
                    }
                    $selected = self::parseCompound(trim(substr($source, $offset + 1, $closing - $offset - 1)), 0, false);
                    if ( null === $selected || null !== $selected['suffix'] || array() !== $selected['compound']['not'] || array() !== $selected['compound']['any'] || null !== $selected['compound']['nth_child'] || $selected['compound']['first_child'] || $selected['compound']['last_child'] ) {
                        return null;
                    }
                    $selectedCompound = $selected['compound'];
                    if ( null !== $selectedCompound['type'] && null !== $compound['type'] ) {
                        return null;
                    }
                    $compound['type'] ??= $selectedCompound['type'];
                    $compound['universal'] = $compound['universal'] || $selectedCompound['universal'];
                    foreach ( array( 'classes', 'ids', 'attributes' ) as $key ) {
                        array_push($compound[$key], ...$selectedCompound[$key]);
                    }
                    if ( 'where' === $lowerName ) {
                        $compound['zero_specificity']['types'] += null === $selectedCompound['type'] ? 0 : 1;
                        foreach ( array( 'classes', 'ids', 'attributes' ) as $key ) {
                            $compound['zero_specificity'][$key] += count($selectedCompound[$key]);
                        }
                    }
                    $offset = $closing + 1;
                    $hasSimple = true;
                    continue;
                }
                if ( 'not' === $lowerName && '(' === ($source[ $offset ] ?? '') ) {
                    $closing = self::matchingParenthesis($source, $offset);
                    if ( null === $closing ) {
                        return null;
                    }
                    $argument = trim(substr($source, $offset + 1, $closing - $offset - 1));
                    // A negated dynamic state describes the resting document, which is
                    // exactly what a static snapshot represents, so it always holds here.
                    if ( 1 === preg_match('/^(?::(?:hover|focus|focus-visible|focus-within|active|visited)\s*)+$/i', $argument) ) {
                        $compound['resting_state_negations'] += preg_match_all('/:/', $argument);
                        $offset = $closing + 1;
                        $hasSimple = true;
                        continue;
                    }
                    // Selectors Level 4 allows complex selectors — arguments
                    // carrying descendant, child, or sibling combinators —
                    // inside `:not()`. Parsing the argument as one compound
                    // silently dropped those combinators, so a source
                    // exclusion such as `.a:not(.wrapper .a)` was evaluated
                    // as `.a:not(.wrapper.a)` and flipped into a false
                    // match. Parse the argument as a full selector and
                    // evaluate it against the source DOM instead; anything
                    // the matcher cannot parse keeps the whole selector
                    // unsupported so the declaration stays source-owned.
                    if ( '' === $argument ) {
                        return null;
                    }
                    $negated = self::parseUncached($argument);
                    if ( ! ($negated['supported'] ?? false) || null !== ($negated['pseudo_state_suffix_span'] ?? null) ) {
                        return null;
                    }
                    $compound['not'][] = array( 'compounds' => $negated['compounds'], 'combinators' => $negated['combinators'] );
                    $offset = $closing + 1;
                    $hasSimple = true;
                    continue;
                }
                if ( in_array($lowerName, array( 'first-child', 'last-child' ), true) && '(' !== ($source[ $offset ] ?? '') ) {
                    $compound['first-child' === $lowerName ? 'first_child' : 'last_child'] = true;
                    $hasSimple = true;
                    continue;
                }
                // `:root` is the document element, and a resting-state structural
                // selector like `:first-child`. The engine emits `:root`-scoped
                // support CSS in several projectors as a specificity device, and
                // author stylesheets use bare `:root` to declare custom
                // properties, so rejecting it left this matcher unable to read
                // selectors the engine itself writes.
                if ( 'root' === $lowerName && '(' !== ($source[ $offset ] ?? '') ) {
                    $compound['root'] = true;
                    $hasSimple = true;
                    continue;
                }
                if ( 'nth-child' === $lowerName && '(' === ($source[ $offset ] ?? '') ) {
                    $closing = strpos($source, ')', $offset + 1);
                    if ( false === $closing ) {
                        return null;
                    }
                    $argument = trim(substr($source, $offset + 1, $closing - $offset - 1));
                    if ( ! preg_match('/^[1-9][0-9]*$/', $argument) ) {
                        return null;
                    }
                    $compound['nth_child'] = (int) $argument;
                    $offset = $closing + 1;
                    $hasSimple = true;
                    continue;
                }
                if ( '(' === ($source[ $offset ] ?? '') || ! $isRightmost || ! in_array($lowerName, array( 'hover', 'focus', 'active', 'visited' ), true) ) {
                    return null;
                }
                if ( null === $suffix ) {
                    $suffix = array( 'start' => $sourceStart + $start, 'end' => $sourceStart + $offset );
                } else {
                    $suffix['end'] = $sourceStart + $offset;
                }
                continue;
            }
            if ( null !== $suffix ) {
                return null;
            }

            if ( '.' === $character || '#' === $character ) {
                ++$offset;
                $name = self::identifier($source, $offset);
                if ( null === $name ) {
                    return null;
                }
                $key = '.' === $character ? 'classes' : 'ids';
                $compound[ $key ][] = $name;
                $hasSimple = true;
                continue;
            }
            if ( '[' === $character ) {
                $attribute = self::attribute($source, $offset);
                if ( null === $attribute ) {
                    return null;
                }
                $compound['attributes'][] = $attribute;
                $hasSimple = true;
                continue;
            }
            if ( '*' === $character ) {
                if ( $hasSimple || $compound['universal'] ) {
                    return null;
                }
                ++$offset;
                $compound['universal'] = true;
                $hasSimple = true;
                continue;
            }
            if ( $hasSimple || '|' === $character ) {
                return null;
            }
            $start = $offset;
            $name = self::identifier($source, $offset);
            if ( null === $name ) {
                return null;
            }
            $compound['type'] = $name;
            $typeSpan = array( 'start' => $sourceStart + $start, 'end' => $sourceStart + $offset, 'name' => $name );
            $hasSimple = true;
        }

        return $hasSimple ? array( 'compound' => $compound, 'suffix' => $suffix, 'type_span' => $typeSpan ) : null;
    }

    /**
     * Offset of the `)` closing the `(` at $open, or null when it never
     * closes. The scan ignores parentheses inside strings, comments, and
     * nested functional pseudo-classes, so a `:not()` argument such as
     * `:where(.a)` or `[title="x)"]` extracts intact.
     */
    private static function matchingParenthesis(string $source, int $open): ?int
    {
        $state = CssSyntaxScanner::state();
        $depth = 1;
        $length = strlen($source);
        // Consume the opening parenthesis so the scanner's own paren counter
        // stays balanced for the rest of the scan.
        $offset = CssSyntaxScanner::consume($source, $open, $state);
        if ( null === $offset ) {
            return null;
        }
        for ( ; $offset < $length; ) {
            $character = $source[ $offset ];
            $before = $state['parens'];
            $next = CssSyntaxScanner::consume($source, $offset, $state);
            if ( null === $next ) {
                return null;
            }
            // The scanner's own paren counter only moves for structural
            // parentheses, so a depth change on a raw bracket byte marks one.
            if ( $next === $offset + 1 && '(' === $character && $state['parens'] === $before + 1 ) {
                ++$depth;
            } elseif ( $next === $offset + 1 && ')' === $character && $state['parens'] === $before - 1 ) {
                --$depth;
                if ( 0 === $depth ) {
                    return $offset;
                }
            }
            $offset = $next;
        }
        return null;
    }

    /** @return array{name: string, operator: string|null, value: string|null, flag: string|null}|null */
    private static function attribute(string $source, int &$offset): ?array
    {        ++$offset;
        self::skipIgnorable($source, $offset);
        $name = self::identifier($source, $offset);
        if ( null === $name ) {
            return null;
        }
        $name = strtolower($name); // HTML attribute names are ASCII-case-insensitive.
        self::skipIgnorable($source, $offset);
        if ( ']' === ($source[ $offset ] ?? '') ) {
            ++$offset;
            return array( 'name' => $name, 'operator' => null, 'value' => null, 'flag' => null );
        }

        $operator = self::attributeOperator($source, $offset);
        if ( null === $operator ) {
            return null;
        }
        self::skipIgnorable($source, $offset);
        $value = self::attributeValue($source, $offset);
        if ( null === $value ) {
            return null;
        }
        self::skipIgnorable($source, $offset);

        $flag = null;
        if ( ']' !== ($source[ $offset ] ?? '') ) {
            $flag = self::identifier($source, $offset);
            if ( null !== $flag ) {
                $flag = strtolower($flag);
            }
            if ( ! in_array($flag, array( 'i', 's' ), true) ) {
                return null;
            }
            self::skipIgnorable($source, $offset);
        }
        if ( ']' !== ($source[ $offset ] ?? '') ) {
            return null;
        }
        ++$offset;

        return array( 'name' => $name, 'operator' => $operator, 'value' => $value, 'flag' => $flag );
    }

    private static function attributeOperator(string $source, int &$offset): ?string
    {
        foreach ( array( '~=', '|=', '^=', '$=', '*=', '=' ) as $candidate ) {
            if ( substr($source, $offset, strlen($candidate)) === $candidate ) {
                $offset += strlen($candidate);
                return $candidate;
            }
        }
        return null;
    }

    private static function attributeValue(string $source, int &$offset): ?string
    {
        $quote = $source[ $offset ] ?? '';
        if ( '"' !== $quote && "'" !== $quote ) {
            return self::identifier($source, $offset);
        }

        ++$offset;
        $value = '';
        while ( $offset < strlen($source) && $source[ $offset ] !== $quote ) {
            if ( "\n" === $source[ $offset ] || "\r" === $source[ $offset ] || "\f" === $source[ $offset ] ) {
                return null;
            }
            $escape = self::escape($source, $offset);
            if ( null === $escape ) {
                $value .= $source[ $offset ];
                ++$offset;
                continue;
            }
            $value .= $escape;
        }
        if ( $quote !== ($source[ $offset ] ?? '') ) {
            return null;
        }
        ++$offset;
        return $value;
    }

    private static function identifier(string $source, int &$offset): ?string
    {
        $start = $offset;
        $first = self::identifierFirstCharacter($source, $offset);
        if ( null === $first ) {
            $offset = $start;
            return null;
        }
        $value = $first;
        while ( $offset < strlen($source) ) {
            $escape = self::escape($source, $offset);
            if ( null !== $escape ) {
                $value .= $escape;
                continue;
            }
            if ( ! self::isIdentifierCharacter($source[ $offset ]) ) {
                break;
            }
            $value .= $source[ $offset ];
            ++$offset;
        }
        return $value;
    }

    private static function identifierFirstCharacter(string $source, int &$offset): ?string
    {
        $escape = self::escape($source, $offset);
        if ( null !== $escape ) {
            return $escape;
        }
        $character = $source[ $offset ] ?? '';
        if ( '-' === $character ) {
            ++$offset;
            $next = self::escape($source, $offset);
            if ( null !== $next ) {
                return '-' . $next;
            }
            if ( '-' !== ($source[ $offset ] ?? '') && ! self::isIdentifierStartCharacter($source[ $offset ] ?? '') ) {
                return null;
            }
            return '-';
        }
        if ( ! self::isIdentifierStartCharacter($character) ) {
            return null;
        }
        ++$offset;
        return $character;
    }

    private static function escape(string $source, int &$offset): ?string
    {
        if ( '\\' !== ($source[ $offset ] ?? '') ) {
            return null;
        }
        $escaped = $source[ $offset + 1 ] ?? '';
        if ( '' === $escaped || "\n" === $escaped || "\r" === $escaped || "\f" === $escaped ) {
            return null;
        }
        $end = CssSyntaxScanner::escapeEnd($source, $offset);
        if ( null === $end ) {
            return null;
        }
        $raw = substr($source, $offset + 1, $end - $offset - 1);
        $offset = $end;
        $hex = preg_replace('/[\x09\x0A\x0C\x0D\x20].*$/', '', $raw);
        if ( '' === $hex || ! ctype_xdigit($hex) ) {
            return $raw;
        }
        $codepoint = hexdec($hex);
        if ( 0 === $codepoint || $codepoint > 0x10ffff || ($codepoint >= 0xd800 && $codepoint <= 0xdfff) ) {
            return "\xef\xbf\xbd";
        }
        return self::utf8($codepoint);
    }

    private static function skipIgnorable(string $source, int &$offset): void
    {
        $length = strlen($source);
        while ( $offset < $length ) {
            if ( CssSyntaxScanner::isCssWhitespace($source[ $offset ]) ) {
                ++$offset;
                continue;
            }
            if ( '/*' !== substr($source, $offset, 2) ) {
                return;
            }
            $end = strpos($source, '*/', $offset + 2);
            if ( false === $end ) {
                $offset = $length;
                return;
            }
            $offset = $end + 2;
        }
    }

    /** @param list<array<string, mixed>> $compounds @param list<string> $combinators */
    private static function matchesAt(DOMElement $element, array $compounds, array $combinators, int $index, ?CssSelectorMatchCache $cache): bool
    {
        if ( ! self::matchesCompound($element, $compounds[ $index ], $cache) ) {
            return false;
        }
        if ( 0 === $index ) {
            return true;
        }

        $combinator = $combinators[ $index - 1 ];
        if ( '>' === $combinator ) {
            return $element->parentNode instanceof DOMElement && self::matchesAt($element->parentNode, $compounds, $combinators, $index - 1, $cache);
        }
        if ( '+' === $combinator ) {
            $previous = self::previousElementSibling($element);
            return null !== $previous && self::matchesAt($previous, $compounds, $combinators, $index - 1, $cache);
        }
        if ( '~' === $combinator ) {
            for ( $previous = self::previousElementSibling($element); null !== $previous; $previous = self::previousElementSibling($previous) ) {
                if ( self::matchesAt($previous, $compounds, $combinators, $index - 1, $cache) ) {
                    return true;
                }
            }
            return false;
        }

        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( self::matchesAt($parent, $compounds, $combinators, $index - 1, $cache) ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $compound */
    private static function matchesCompound(DOMElement $element, array $compound, ?CssSelectorMatchCache $cache): bool
    {
        if ( null !== $compound['type'] && 0 !== strcasecmp($element->tagName, $compound['type']) ) {
            return false;
        }
        $actualId = array() !== $compound['ids'] ? (null !== $cache ? ($cache->attribute($element, 'id') ?? '') : $element->getAttribute('id')) : '';
        foreach ( $compound['ids'] as $id ) {
            if ( $actualId !== $id ) {
                return false;
            }
        }
        if ( array() !== $compound['classes'] ) {
            $classes = $cache?->classTokens($element) ?? (preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($element->getAttribute('class'))) ?: array());
            foreach ( $compound['classes'] as $class ) {
                if ( ! in_array($class, $classes, true) ) {
                    return false;
                }
            }
        }
        foreach ( $compound['attributes'] as $attribute ) {
            if ( ! self::matchesAttribute($element, $attribute, $cache) ) {
                return false;
            }
        }
        foreach ( $compound['not'] as $negated ) {
            if ( self::matchesAt($element, $negated['compounds'], $negated['combinators'], count($negated['compounds']) - 1, $cache) ) {
                return false;
            }
        }
        foreach ( $compound['any'] ?? array() as $group ) {
            $matched = false;
            foreach ( $group['alternatives'] as $alternative ) {
                if ( self::matchesAt($element, $alternative['compounds'], $alternative['combinators'], count($alternative['compounds']) - 1, $cache) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }
        $childIndex = null;
        if ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) {
            $childIndex = 1;
            for ( $previous = self::previousElementSibling($element); null !== $previous; $previous = self::previousElementSibling($previous) ) {
                ++$childIndex;
            }
        }
        if ( null !== $compound['nth_child'] && $childIndex !== $compound['nth_child'] ) {
            return false;
        }
        if ( $compound['first_child'] && 1 !== $childIndex ) {
            return false;
        }
        if ( $compound['last_child'] && self::hasNextElementSibling($element) ) {
            return false;
        }
        if ( ($compound['root'] ?? false) && ( null === $element->ownerDocument || $element !== $element->ownerDocument->documentElement ) ) {
            return false;
        }
        return true;
    }

    private static function hasNextElementSibling(DOMElement $element): bool
    {
        for ( $next = $element->nextSibling; $next instanceof DOMNode; $next = $next->nextSibling ) {
            if ( $next instanceof DOMElement ) {
                return true;
            }
        }
        return false;
    }

    /** @param array{name: string, operator: string|null, value: string|null, flag: string|null} $attribute */
    private static function matchesAttribute(DOMElement $element, array $attribute, ?CssSelectorMatchCache $cache): bool
    {
        if ( null !== $cache ) {
            $actual = $cache->attribute($element, $attribute['name']);
            if ( null === $actual ) {
                return false;
            }
        } elseif ( ! $element->hasAttribute($attribute['name']) ) {
            return false;
        } else {
            $actual = $element->getAttribute($attribute['name']);
        }
        if ( null === $attribute['operator'] ) {
            return true;
        }

        $expected = (string) $attribute['value'];
        if ( 'i' === $attribute['flag'] ) {
            $actual = strtolower($actual);
            $expected = strtolower($expected);
        }
        return match ( $attribute['operator'] ) {
            '=' => $actual === $expected,
            '~=' => in_array($expected, preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($actual)) ?: array(), true),
            '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
            '^=' => '' !== $expected && str_starts_with($actual, $expected),
            '$=' => '' !== $expected && str_ends_with($actual, $expected),
            '*=' => '' !== $expected && str_contains($actual, $expected),
        };
    }

    /** @param list<array<string, mixed>> $compounds */
    private static function hasUnmodeledHtmlAttributeValueSemantics(array $compounds): bool
    {
        foreach ( $compounds as $compound ) {
            foreach ( $compound['attributes'] as $attribute ) {
                if ( null !== $attribute['operator'] && null === $attribute['flag'] && isset(self::ENUMERATED_ATTRIBUTES[ $attribute['name'] ]) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function previousElementSibling(DOMElement $element): ?DOMElement
    {
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement ) {
                return $node;
            }
        }
        return null;
    }

    private static function isIdentifierStartCharacter(string $character): bool
    {
        return ctype_alpha($character) || '_' === $character || ('' !== $character && ord($character) >= 0x80);
    }

    private static function isIdentifierCharacter(string $character): bool
    {
        return self::isIdentifierStartCharacter($character) || ctype_digit($character) || '-' === $character;
    }

    private static function utf8(int $codepoint): string
    {
        if ( $codepoint <= 0x7f ) {
            return chr($codepoint);
        }
        if ( $codepoint <= 0x7ff ) {
            return chr(0xc0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3f));
        }
        if ( $codepoint <= 0xffff ) {
            return chr(0xe0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
        }
        return chr(0xf0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3f)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
    }

    /** @return array{supported: false, reason: string, compounds: list<array<string, mixed>>, combinators: list<string>, type_spans: list<array{start: int, end: int, name: string, compound: int}>, rightmost_compound_span: null, pseudo_state_suffix_span: null, rightmost_rewrite_end: null} */
    private static function unsupported(string $reason): array
    {
        return array( 'supported' => false, 'reason' => $reason, 'compounds' => array(), 'combinators' => array(), 'type_spans' => array(), 'rightmost_compound_span' => null, 'pseudo_state_suffix_span' => null, 'rightmost_rewrite_end' => null );
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Safely visits style-rule selector preludes without parsing or reserializing CSS.
 */
final class CssStylesheetTransformer
{
    private CssRuleTreeCache $localTrees;

    /** @var \WeakMap<CssRuleTree, array<int, list<array{start: int, boundary: int, end: int, prelude: string, body: string, kind: int}>>> */
    private \WeakMap $renderScopes;

    public function __construct()
    {
        $this->localTrees = new CssRuleTreeCache();
        $this->renderScopes = new \WeakMap();
    }

    private function tree(string $css): CssRuleTree
    {
        return CssRuleTreeCache::parse($css, $this->localTrees);
    }

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
        if ( ! $this->tree($stylesheet)->recoverable ) {
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
        if ( ! $this->tree($stylesheet)->recoverable ) {
            return $stylesheet;
        }
        return $this->transformRules($stylesheet, static fn (string $prelude): string => $prelude, $transformStyleRule);
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
        foreach ($this->tree($body)->rules() as $rule) {
            ['start' => $offset, 'boundary' => $boundary, 'end' => $end] = $rule;
            if (';' === $body[$boundary]) continue;
            $prelude = substr($body, $offset, $boundary - $offset);
            if ( preg_match('/^(?:\s|\/\*.*?\*\/)*--[^\s:]+\s*:/s', $prelude) ) {
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
            $start = $end + 1;
        }
        if ( $start < strlen($body) ) {
            $parts[] = array('declarations' => substr($body, $start));
        }
        return $parts;
    }

    /**
     * Visit every style rule with its enclosing safe-to-walk at-rules.
     *
     * Visited preludes and ancestor at-rule preludes carry no author comments:
     * a comment before a rule, between rules or inside a selector list is not
     * part of any selector, so a consumer that writes a selector or condition
     * back into a stylesheet never copies one.
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
        $this->visitRules($stylesheet, null, $visitKeyframes, array());
    }

    /**
     * The stylesheet without rules a later identical rule in the same
     * conditional groups repeats: the later copy wins wherever the earlier
     * one would, so the cascade is unchanged. Nor does it keep a style rule
     * a later one restates in full ({@see withoutDominatedUnits()}), nor a
     * style rule that declares nothing ({@see withoutEmptyStyleRules()}).
     * Malformed input is returned unchanged.
     */
    public function compactStylesheet(string $css): string
    {
        if ( ! $this->isWellFormedStylesheet($css) ) {
            return $css;
        }
        $units = array();
        $this->collectRuleUnits($css, array(), $units);
        return $this->serializeRuleUnits($this->withoutDominatedUnits($this->withoutEarlierDuplicates(array($this->withoutEmptyStyleRules($units)))));
    }

    /**
     * The units without style rules whose block declares nothing. Projection
     * empties a rule when it routes every declaration to another carrier (an
     * inline-style guard, a native attribute); the empty rule styles no
     * element, yet ships its selector. A rule that first names a cascade
     * layer keeps the layer's order, and a leading comment (a license) stays.
     *
     * @param list<array{context: list<string>, text: string, dedupable: bool}> $units
     * @return list<array{context: list<string>, text: string, dedupable: bool}>
     */
    private function withoutEmptyStyleRules(array $units): array
    {
        $named = array();
        $kept = array();
        foreach ( $units as $unit ) {
            $origin = false;
            foreach ( self::layerNames($unit) as $name ) {
                if ( ! isset($named[ $name ]) ) {
                    $named[ $name ] = true;
                    $origin = true;
                }
            }
            if ( ! $origin && ! str_contains($unit['text'], '/*') && 1 === preg_match('/^\s*[^{}@\s][^{}]*\{\s*\}\s*$/', $unit['text']) ) {
                continue;
            }
            $kept[] = $unit;
        }
        return $kept;
    }

    /**
     * The units without each style rule a later rule in the same conditional
     * groups makes inert: the later rule selects at least every arm of the
     * earlier one and repeats each of its declarations. For any element the
     * earlier rule matches, the later one matches with at least the same
     * specificity, later in the cascade, setting every property the earlier
     * one sets, so the earlier rule never decides a computed value.
     * Projection emits such pairs when a source rule's subjects are restated
     * for several native carriers (a reset's image arms, say). A later rule
     * whose extra arms a browser could reject as a whole (vendor pseudos,
     * `:has()`) does not stand in for the earlier one.
     *
     * @param list<array{context: list<string>, text: string, dedupable: bool}> $units
     * @return list<array{context: list<string>, text: string, dedupable: bool}>
     */
    private function withoutDominatedUnits(array $units): array
    {
        $rules = array();
        $byArm = array();
        foreach ( $units as $index => $unit ) {
            $rule = $this->dominanceRule($unit);
            if ( null === $rule ) {
                continue;
            }
            $rules[ $index ] = $rule;
            foreach ( array_keys($rule['arms']) as $arm ) {
                $byArm[ $rule['context'] . "\0" . $arm ][] = $index;
            }
        }
        $dropped = false;
        foreach ( $rules as $index => $rule ) {
            $first = (string) array_key_first($rule['arms']);
            foreach ( $byArm[ $rule['context'] . "\0" . $first ] ?? array() as $later ) {
                if ( $later <= $index ) {
                    continue;
                }
                $other = $rules[ $later ];
                if ( array() !== array_diff_key($rule['arms'], $other['arms']) || array() !== array_diff_key($rule['declarations'], $other['declarations']) ) {
                    continue;
                }
                foreach ( array_keys(array_diff_key($other['arms'], $rule['arms'])) as $extra ) {
                    if ( preg_match('/::?-|:has\(/i', (string) $extra) ) {
                        continue 2;
                    }
                }
                unset($units[ $index ]);
                $dropped = true;
                break;
            }
        }
        return $dropped ? array_values($units) : $units;
    }

    /**
     * The stylesheet with each later rule whose selector list repeats an
     * earlier rule's, in the same conditional groups, folded into that
     * earlier rule. Projection can give several source rules one long
     * selector list (a reset's `*` and its pseudo-element twins), which then
     * ships once per rule. The folded declarations keep their order after
     * the earlier ones, and are only moved when no rule between the two
     * declares a property of the same cascade family, so every element keeps
     * its computed values. Malformed input is returned unchanged.
     */
    public function mergeRepeatedSelectorRules(string $css): string
    {
        if ( ! str_contains($css, '{') || ! $this->isWellFormedStylesheet($css) ) {
            return $css;
        }
        $units = array();
        $this->collectRuleUnits($css, array(), $units);
        $anchors = array();
        $families = array();
        $merged = false;
        foreach ( $units as $index => $unit ) {
            // Families are read lazily: only units between two candidates matter.
            $key = $this->repeatableSelectorRuleKey($unit);
            if ( null === $key ) {
                continue;
            }
            if ( isset($anchors[ $key ]) ) {
                $anchor = $anchors[ $key ];
                [$lead, $rule] = self::splitLeadingComments($unit['text']);
                // Never drop a comment (a license, say) with the folded rule.
                $own = str_contains($lead, '/*') ? array( '*' => true ) : null;
                $own ??= self::cascadePropertyFamilies($rule);
                $blocked = isset($own['*']);
                for ( $between = $anchor + 1; $between < $index && ! $blocked; ++$between ) {
                    if ( ! isset($units[ $between ]) ) {
                        continue;
                    }
                    // Unparsed trailing text could hide anything.
                    $families[ $between ] ??= $units[ $between ]['dedupable'] || str_contains($units[ $between ]['text'], '{') ? self::cascadePropertyFamilies(self::splitLeadingComments($units[ $between ]['text'])[1]) : array( '*' => true );
                    $blocked = self::cascadeFamiliesOverlap($own, $families[ $between ]);
                }
                if ( ! $blocked ) {
                    $target = $units[ $anchor ]['text'];
                    $close = strrpos($target, '}');
                    $brace = strpos($rule, '{');
                    $units[ $anchor ]['text'] = rtrim(substr($target, 0, (int) $close), " \t\n\r\f;") . ';' . substr($rule, (int) $brace + 1);
                    unset($units[ $index ], $families[ $anchor ]);
                    $merged = true;
                    continue;
                }
            }
            $anchors[ $key ] = $index;
        }
        return $merged ? $this->serializeRuleUnits(array_values($units)) : $css;
    }

    /**
     * The stylesheet with each later rule whose declaration block repeats an
     * earlier rule's, in the same conditional groups, folded into that
     * earlier rule's selector list. Projection restates one source rule for
     * each carrier it reaches (a class, its control twin, the editor canvas),
     * and each restatement otherwise ships the same declarations again. The
     * folded selectors move to the earlier rule only when every rule between
     * the two either declares no property of the same cascade family or
     * selects no element (or pseudo-element box) the folded selectors do
     * ({@see selectorListsDisjoint()}), so every element keeps its computed
     * values. Each selector joins the list as it is, never inside `:is()`,
     * so it keeps its own specificity. Two lists join only when the pseudo-classes
     * and pseudo-elements outside the universally supported set are the same
     * in both: a browser that drops one list for an unknown pseudo drops the
     * other too, so joining them never discards a rule that used to apply.
     * Malformed input is returned unchanged.
     */
    public function mergeRepeatedDeclarationRules(string $css, bool $includeDocumentRules = false, bool $classCarriersOnly = false): string
    {
        if ( ! str_contains($css, '{') || ! $this->isWellFormedStylesheet($css) ) {
            return $css;
        }
        $units = array();
        $this->collectRuleUnits($css, array(), $units);
        $anchors = array();
        $families = array();
        $subjects = array();
        $merged = false;
        foreach ( $units as $index => $unit ) {
            $parts = $this->repeatableDeclarationRuleParts($unit, $includeDocumentRules);
            if ( null === $parts ) {
                continue;
            }
            [$lead, $prelude, $body] = $parts;
            // Support rules also own structural roots and native descendants.
            // Only independent class carriers may share a declaration body;
            // keep those structural rules standalone for later consumers.
            if ($classCarriersOnly && 1 !== preg_match('/^\.[a-zA-Z_][a-zA-Z0-9_-]*(?:\s*,\s*\.[a-zA-Z_][a-zA-Z0-9_-]*)*$/D', $prelude)) {
                continue;
            }
            $key = implode("\0", array_map(self::normalizedCss(...), $unit['context'])) . "\0" . self::normalizedCss($body) . "\0" . implode(',', self::uncommonPseudoNames($prelude));
            if ( isset($anchors[ $key ]) ) {
                $anchor = $anchors[ $key ];
                // Never drop a comment (a license, say) with the folded rule.
                $own = str_contains($lead, '/*') ? array( '*' => true ) : self::cascadePropertyFamilies($prelude . '{' . $body . '}');
                $blocked = isset($own['*']);
                for ( $between = $anchor + 1; $between < $index && ! $blocked; ++$between ) {
                    if ( ! isset($units[ $between ]) ) {
                        continue;
                    }
                    $families[ $between ] ??= $units[ $between ]['dedupable'] || str_contains($units[ $between ]['text'], '{') ? self::cascadePropertyFamilies(self::splitLeadingComments($units[ $between ]['text'])[1]) : array( '*' => true );
                    $blocked = self::cascadeFamiliesOverlap($own, $families[ $between ])
                        && ( isset($families[ $between ]['*']) || ! self::selectorListsDisjoint($prelude, $this->styleRulePrelude($units[ $between ]), $subjects) );
                }
                if ( ! $blocked ) {
                    [$anchorLead, $anchorPrelude, $anchorBody] = $this->repeatableDeclarationRuleParts($units[ $anchor ], $includeDocumentRules) ?? array( '', '', '' );
                    $units[ $anchor ]['text'] = $anchorLead . $anchorPrelude . ',' . $prelude . '{' . $anchorBody . '}';
                    unset($units[ $index ], $families[ $anchor ]);
                    $merged = true;
                    continue;
                }
            }
            $anchors[ $key ] = $index;
        }
        return $merged ? $this->serializeRuleUnits(array_values($units)) : $css;
    }

    /**
     * The selector list of a plain style rule unit, or null for anything else
     * (at-rules, nested rules, statements, comments inside the selector).
     *
     * @param array{context: list<string>, text: string, dedupable: bool} $unit
     */
    private function styleRulePrelude(array $unit): ?string
    {
        if ( ! $unit['dedupable'] ) {
            return null;
        }
        $rule = rtrim(self::splitLeadingComments($unit['text'])[1]);
        $brace = strpos($rule, '{');
        if ( false === $brace || $this->isAtRule($rule) || ! str_ends_with($rule, '}') || str_contains(substr($rule, $brace + 1, -1), '{') ) {
            return null;
        }
        $prelude = trim(substr($rule, 0, $brace));
        return '' === $prelude || str_contains($prelude, '/*') ? null : $prelude;
    }

    /**
     * Whether no element, nor any pseudo-element box, can be selected by an
     * arm of each list. Two arms are disjoint when their subject compounds
     * select different pseudo-elements (or one the element itself and the
     * other a pseudo-element of it), or require two different ids: an
     * element has one id. Ids count where they constrain the subject: as a
     * simple selector, or as the only compound inside `:is()`/`:where()`
     * (specificity markers and carriers write `:where(#id)`); never inside
     * `:not()` or `:has()`. Ids compare case-insensitively, which also holds
     * for documents in quirks mode. Anything this cannot read is not proven
     * disjoint.
     *
     * @param array<string, array{ids: array<string, true>, pseudo: string}|null> $cache
     */
    private static function selectorListsDisjoint(string $first, ?string $second, array &$cache = array()): bool
    {
        if ( null === $second ) {
            return false;
        }
        $read = static function (string $list) use (&$cache): ?array {
            if ( array_key_exists($list, $cache) ) {
                return $cache[ $list ];
            }
            $arms = self::splitSelectorList($list);
            $subjects = null === $arms || array() === $arms ? null : array();
            foreach ( $arms ?? array() as $arm ) {
                $subject = self::subjectConstraints(trim($arm));
                if ( null === $subject ) {
                    $subjects = null;
                    break;
                }
                $subjects[] = $subject;
            }
            if ( count($cache) >= 4096 ) {
                $cache = array();
            }
            return $cache[ $list ] = $subjects;
        };
        $left = $read($first);
        $right = $read($second);
        if ( null === $left || null === $right ) {
            return false;
        }
        foreach ( $left as $a ) {
            foreach ( $right as $b ) {
                if ( $a['pseudo'] === $b['pseudo'] && count($a['ids'] + $b['ids']) < 2 ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The ids an arm's subject compound requires and the pseudo-element it
     * selects ('' for the element itself), or null when the arm cannot be
     * read safely.
     *
     * @return array{ids: array<string, true>, pseudo: string}|null
     */
    private static function subjectConstraints(string $arm): ?array
    {
        if ( '' === $arm || str_contains($arm, '/*') || false !== strpbrk($arm, '\\&|"\'') ) {
            return null;
        }
        $tokens = CssSelectorTokenizer::tokenize($arm);
        if ( ! $tokens['supported'] || '' === $tokens['rightmost_compound'] ) {
            return null;
        }
        $ids = array();
        $pseudo = '';
        return self::compoundConstraints($tokens['rightmost_compound'], $ids, $pseudo) ? array( 'ids' => $ids, 'pseudo' => $pseudo ) : null;
    }

    /** @param array<string, true> $ids */
    private static function compoundConstraints(string $compound, array &$ids, string &$pseudo, bool $nested = false): bool
    {
        $identifier = '-?[a-zA-Z_][a-zA-Z0-9_-]*|--[a-zA-Z0-9_-]*';
        for ( $i = 0, $length = strlen($compound); $i < $length; ) {
            $char = $compound[ $i ];
            if ( '[' === $char ) {
                $close = CssSyntaxScanner::matchingClose($compound, $i);
                if ( null === $close ) {
                    return false;
                }
                $i = $close + 1;
                continue;
            }
            if ( '#' === $char ) {
                if ( ! preg_match('/\G#(' . $identifier . ')/', $compound, $match, 0, $i) ) {
                    return false;
                }
                $ids[ strtolower($match[1]) ] = true;
                $i += strlen($match[0]);
                continue;
            }
            if ( ':' === $char ) {
                if ( ! preg_match('/\G(::?)(' . $identifier . ')(\()?/', $compound, $match, 0, $i) ) {
                    return false;
                }
                $name = strtolower($match[2]);
                $element = '::' === $match[1] || in_array($name, array( 'before', 'after', 'first-line', 'first-letter' ), true);
                if ( $element ) {
                    // A pseudo-element ends the subject; a functional one
                    // (`::part()`, `::slotted()`) or one inside `:is()` is not read.
                    if ( $nested || isset($match[3]) || '' !== $pseudo ) {
                        return false;
                    }
                    $pseudo = $name;
                    $i += strlen($match[0]);
                    continue;
                }
                $i += strlen($match[0]);
                if ( ! isset($match[3]) ) {
                    continue;
                }
                $close = CssSyntaxScanner::matchingClose($compound, $i - 1);
                if ( null === $close ) {
                    return false;
                }
                $argument = trim(substr($compound, $i, $close - $i));
                $i = $close + 1;
                if ( ! in_array($name, array( 'is', 'where' ), true) ) {
                    continue;
                }
                // Only a lone compound constrains the subject itself.
                $inner = CssSelectorTokenizer::tokenize($argument);
                if ( '' === $argument || str_contains($argument, ',') || ! $inner['supported'] || 1 !== count($inner['compounds']) ) {
                    continue;
                }
                $innerPseudo = '';
                $innerIds = array();
                if ( self::compoundConstraints($argument, $innerIds, $innerPseudo, true) ) {
                    $ids += $innerIds;
                }
                continue;
            }
            if ( '(' === $char || ')' === $char ) {
                return false;
            }
            ++$i;
        }
        return true;
    }

    /**
     * A plain style rule's leading comments, selector list and declaration
     * block, or null for at-rules, nested rules, comments inside the
     * selector and empty blocks.
     *
     * @param array{context: list<string>, text: string, dedupable: bool} $unit
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function repeatableDeclarationRuleParts(array $unit, bool $includeDocumentRules = false): ?array
    {
        if ( ! $unit['dedupable'] ) {
            return null;
        }
        [$lead, $rule] = self::splitLeadingComments($unit['text']);
        $rule = rtrim($rule);
        $brace = strpos($rule, '{');
        if ( false === $brace || $this->isAtRule($rule) || ! str_ends_with($rule, '}') ) {
            return null;
        }
        $prelude = trim(substr($rule, 0, $brace));
        $body = substr($rule, $brace + 1, -1);
        if ( '' === $prelude || str_contains($prelude, '/*') || str_contains($body, '{') || str_contains($body, '}') || str_contains($body, '/*') || '' === trim($body) || null === self::splitSelectorList($prelude) || (!$includeDocumentRules && self::namesDocumentOrEveryElement($prelude)) ) {
            return null;
        }
        return array( $lead, $prelude, $body );
    }

    /**
     * Whether an arm of a selector list selects the document (`:root`,
     * `html`, `body`, the editor canvas body) or every element (`*`). Those
     * rules are not restatements of one author block across carriers: they
     * are the document-context and reset surfaces that Global Styles, the
     * editor canvas and route-scoped resets each own by their own selector,
     * so they stay standalone.
     *
     * Only an arm's subject compound decides, outside functional
     * pseudo-class arguments; an editor-canvas restatement keeps its own
     * selector for the editor extract wherever it names the canvas. A route gate (`:where(:root.be-rs-…) a`,
     * `X:where(:root:not(.be-rs-…),:root:not(.be-rs-…) *)`) or a specificity
     * shim (`:root:root:root :is(…)`) names the root only to condition or
     * weigh a carrier rule; the rule still styles that carrier, and its
     * restatements for other carriers ship their shared block once.
     */
    private static function namesDocumentOrEveryElement(string $prelude): bool
    {
        $strip = static fn (string $selector): string => preg_replace(array( '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '/\\\\./s', '/\\[[^\\]]*\\]/' ), '', $selector) ?? $selector;
        $document = '/(?:^|[\\s,>+~(])(?:\\*|html|body|:root)(?![\\w-])/i';
        foreach ( self::splitSelectorList($prelude) ?? array( $prelude ) as $arm ) {
            if ( 1 === preg_match('/\\.editor-styles-wrapper(?![\\w-])/', $arm) ) {
                return true;
            }
            $tokens = CssSelectorTokenizer::tokenize(trim($arm));
            // An arm this cannot read keeps the whole-arm reading.
            if ( ! $tokens['supported'] || '' === $tokens['rightmost_compound'] ) {
                if ( 1 === preg_match($document, $strip($arm)) ) {
                    return true;
                }
                continue;
            }
            $subject = $strip($tokens['rightmost_compound']);
            do {
                $subject = preg_replace('/\\([^()]*\\)/', '', $subject, -1, $count) ?? $subject;
            } while ( $count > 0 );
            if ( 1 === preg_match('/^(?:\\*|html|body)(?![\\w-])|:root(?![\\w-])/i', trim($subject)) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The sorted pseudo-class and pseudo-element names in a selector list
     * that not every engine parses (vendor-prefixed and recent ones).
     *
     * @return list<string>
     */
    private static function uncommonPseudoNames(string $prelude): array
    {
        static $common = array(
            'active', 'after', 'before', 'checked', 'default', 'disabled', 'empty', 'enabled', 'first-child', 'first-letter', 'first-line',
            'first-of-type', 'focus', 'focus-visible', 'focus-within', 'hover', 'in-range', 'indeterminate', 'invalid', 'is', 'lang', 'last-child',
            'last-of-type', 'link', 'marker', 'not', 'nth-child', 'nth-last-child', 'nth-last-of-type', 'nth-of-type', 'only-child', 'only-of-type',
            'optional', 'out-of-range', 'placeholder', 'placeholder-shown', 'read-only', 'read-write', 'required', 'root', 'selection', 'target',
            'valid', 'visited', 'where',
        );
        // Strings and attribute values can hold colons that name no pseudo.
        $bare = preg_replace(array( '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '/\\\\./s' ), '', $prelude) ?? $prelude;
        preg_match_all('/::?(-?[a-zA-Z][a-zA-Z0-9-]*)/', $bare, $matches);
        $names = array_values(array_unique(array_diff(array_map('strtolower', $matches[1]), $common)));
        sort($names);
        return $names;
    }

    /**
     * A plain style rule's context key, arm set and declaration set, or null
     * for anything {@see repeatableSelectorRuleKey()} does not fold and for a
     * rule led by a comment.
     *
     * @param array{context: list<string>, text: string, dedupable: bool} $unit
     * @return array{context: string, arms: array<string, true>, declarations: array<string, true>}|null
     */
    private function dominanceRule(array $unit): ?array
    {
        if ( null === $this->repeatableSelectorRuleKey($unit) ) {
            return null;
        }
        [$lead, $rule] = self::splitLeadingComments($unit['text']);
        if ( '' !== trim($lead) || str_contains($rule, '/*') ) {
            return null;
        }
        $brace = (int) strpos($rule, '{');
        $arms = self::splitSelectorList(trim(substr($rule, 0, $brace)));
        if ( null === $arms || array() === $arms ) {
            return null;
        }
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel(substr(rtrim($rule), $brace + 1, -1), array( ';' )) as $declaration ) {
            $declaration = self::normalizedCss($declaration);
            if ( '' !== $declaration ) {
                $declarations[ $declaration ] = true;
            }
        }
        if ( array() === $declarations ) {
            return null;
        }
        return array(
            'context' => implode("\0", array_map(self::normalizedCss(...), $unit['context'])),
            'arms' => array_fill_keys(array_map(self::normalizedCss(...), $arms), true),
            'declarations' => $declarations,
        );
    }

    /**
     * A plain style rule's conditional groups and selector list, or null for
     * at-rules, nested rules, comments inside the selector and empty blocks.
     *
     * @param array{context: list<string>, text: string, dedupable: bool} $unit
     */
    private function repeatableSelectorRuleKey(array $unit): ?string
    {
        if ( ! $unit['dedupable'] ) {
            return null;
        }
        [, $rule] = self::splitLeadingComments($unit['text']);
        $brace = strpos($rule, '{');
        if ( false === $brace || $this->isAtRule($rule) || ! str_ends_with(rtrim($rule), '}') ) {
            return null;
        }
        $prelude = trim(substr($rule, 0, $brace));
        $body = substr(rtrim($rule), $brace + 1, -1);
        if ( '' === $prelude || str_contains($prelude, '/*') || str_contains($body, '{') || str_contains($body, '}') || '' === trim($body) ) {
            return null;
        }
        return implode("\0", array_map(self::normalizedCss(...), $unit['context'])) . "\0" . self::normalizedCss($prelude);
    }

    /**
     * CSS with insignificant whitespace collapsed; quoted strings are kept
     * verbatim. Space before a colon is a descendant combinator, so only the
     * space after one goes.
     */
    private static function normalizedCss(string $css): string
    {
        $parts = preg_split('/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/s', trim($css), -1, PREG_SPLIT_DELIM_CAPTURE) ?: array( $css );
        foreach ( $parts as $index => $part ) {
            if ( 0 === $index % 2 ) {
                $parts[ $index ] = preg_replace(array('/\s+/', '/\s*([{};,>])\s*/', '/:\s+/'), array(' ', '$1', ':'), $part) ?? $part;
            }
        }
        return rtrim(str_replace(';}', '}', implode('', $parts)), ';');
    }

    /**
     * Units in order, without any dedupable unit a later one repeats in the
     * same conditional groups. A layer's first appearance fixes its order, so
     * the unit that first names a layer stays in place.
     *
     * @param list<list<array{context: list<string>, text: string, dedupable: bool}>> $stylesheetUnits
     * @return list<array{context: list<string>, text: string, dedupable: bool}>
     */
    private function withoutEarlierDuplicates(array $stylesheetUnits): array
    {
        $layerOrigins = array();
        $named = array();
        foreach ( $stylesheetUnits as $sheet => $units ) {
            foreach ( $units as $index => $unit ) {
                foreach ( self::layerNames($unit) as $name ) {
                    if ( ! isset($named[ $name ]) ) {
                        $named[ $name ] = true;
                        $layerOrigins[ $sheet ][ $index ] = true;
                    }
                }
            }
        }
        $kept = array();
        $seen = array();
        foreach ( array_reverse($stylesheetUnits, true) as $sheet => $units ) {
            foreach ( array_reverse($units, true) as $index => $unit ) {
                if ( $unit['dedupable'] ) {
                    $key = self::ruleUnitKey($unit);
                    if ( isset($seen[ $key ]) && ! isset($layerOrigins[ $sheet ][ $index ]) ) {
                        continue;
                    }
                    $seen[ $key ] = true;
                }
                $kept[] = $unit;
            }
        }
        return array_reverse($kept);
    }

    public function concatenateWithoutRedundantRules(array $stylesheets): string
    {
        foreach ( $stylesheets as $stylesheet ) {
            if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
                return implode("\n", $stylesheets);
            }
        }

        $stylesheetUnits = array();
        foreach ( $stylesheets as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            $stylesheetUnits[] = $units;
        }
        return $this->serializeRuleUnits($this->withoutEarlierDuplicates($stylesheetUnits));
    }

    /**
     * The qualified layer names a unit declares, outermost first: each named
     * block layer around it, then the names of a layer statement it is.
     *
     * @param array{context: list<string>, text: string, dedupable: bool} $unit
     * @return list<string>
     */
    private static function layerNames(array $unit): array
    {
        // Most units name no layer; skip their comment-stripping scans.
        if ( false === stripos($unit['text'] . implode('', $unit['context']), 'layer') ) {
            return array();
        }
        $path = array();
        $names = array();
        foreach ( $unit['context'] as $prelude ) {
            if ( 'layer' === self::atRuleName($prelude) ) {
                $path[] = trim(substr(trim(CssSyntaxScanner::withoutComments($prelude)), strlen('@layer')));
                $names[] = implode('.', $path);
            }
        }
        $statement = trim(CssSyntaxScanner::withoutComments($unit['text']));
        if ( 'layer' === self::atRuleName($statement) && str_ends_with($statement, ';') ) {
            foreach ( explode(',', substr($statement, strlen('@layer'), -1)) as $name ) {
                $names[] = implode('.', array_merge($path, array( trim($name) )));
            }
        }
        return $names;
    }

    /**
     * The rules of `$stylesheets`, in order and each once, that do not occur
     * with the same text inside the same conditional group rules in `$present`.
     * Malformed input yields the stylesheets unchanged.
     * When preserving cascade for a later-loaded page projection, also keep
     * every later shared rule that may set a property an already kept rule
     * sets: the page stylesheet loads after the shared one, so a kept rule
     * would otherwise outrank the later shared rule it originally lost to.
     * Kept shared rules count too, since re-emitting one moves it later.
     * With a route match index and owner, a shared rule is only re-emitted
     * when it may style an element of that page's document that an already
     * kept rule styles too: family overlap alone does not prove two rules
     * compete. Unreadable selectors and missing documents keep the rule,
     * they never drop it.
     *
     * @param list<string> $stylesheets
     * @param list<string> $present
     */
    public function rulesAbsentFrom(array $stylesheets, array $present, bool $preserveCascade = false, ?RouteSelectorMatchIndex $matchIndex = null, ?string $owner = null): string
    {
        foreach ( array_merge($stylesheets, $present) as $stylesheet ) {
            if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
                return implode("\n", $stylesheets);
            }
        }
        $seen = array();
        // Every route reconciles against the same shared stylesheet: its
        // rule keys are derived once per compose pass, keyed on its bytes.
        foreach ( $present as $stylesheet ) {
            $seen += CssRuleTreeCache::memo('present-rule-unit-keys', $stylesheet, function () use ($stylesheet): array {
                $units = $keys = array();
                $this->collectRuleUnits($stylesheet, array(), $units);
                foreach ( $units as $unit ) {
                    $keys[ self::ruleUnitKey($unit) ] = 1;
                }
                return $keys;
            });
        }
        $kept = array();
        $keptProperties = array();
        // With page markup available, kept rules are indexed by the document
        // elements they style, so a later shared rule is re-emitted only when
        // it styles an element a kept rule styles with an overlapping family.
        $selectorProof = $preserveCascade && null !== $matchIndex && null !== $owner && '' !== $owner;
        $elementFamilies = array();
        $unknownFamilies = array();
        foreach ( $stylesheets as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            foreach ( $units as $unit ) {
                $key = self::ruleUnitKey($unit);
                $properties = $preserveCascade ? self::cascadePropertyFamilies($unit['text']) : array();
                $duplicate = $unit['dedupable'] && isset($seen[ $key ]);
                $targets = $selectorProof && ( ! $duplicate || self::cascadeFamiliesOverlap($properties, $keptProperties) )
                    ? self::cascadeTargets($matchIndex, (string) $owner, $unit['text'])
                    : null;
                $keep = ! $duplicate || self::cascadeFamiliesOverlap($properties, $keptProperties);
                if ( $keep && $duplicate && $selectorProof ) {
                    if ( null === $targets || self::cascadeFamiliesOverlap($properties, $unknownFamilies) ) {
                        // An unprovable match set may meet any kept rule.
                        $keep = true;
                    } else {
                        $keep = false;
                        foreach ( $targets as $id => $_ ) {
                            if ( isset($elementFamilies[ $id ]) && self::cascadeFamiliesOverlap($properties, $elementFamilies[ $id ]) ) {
                                $keep = true;
                                break;
                            }
                        }
                    }
                }
                if ( $keep ) {
                    $kept[] = $unit;
                    $keptProperties += $properties;
                    if ( $selectorProof ) {
                        if ( null === $targets ) {
                            $unknownFamilies += $properties;
                        } else {
                            foreach ( array_keys($targets) as $id ) {
                                $elementFamilies[ $id ] = ( $elementFamilies[ $id ] ?? array() ) + $properties;
                            }
                        }
                    }
                }
                $seen[ $key ] = true;
            }
        }
        return $this->serializeRuleUnits($kept);
    }

    /**
     * What a stylesheet may set on one route's document: the cascade families
     * (see cascadePropertyFamilies) it declares on each element it may style,
     * or null when that cannot be proven — malformed CSS, an unreadable
     * selector or an arm the index cannot evaluate. Two stylesheets whose
     * footprints do not overlap can trade places on that route without
     * changing its cascade.
     *
     * @return array<int|string, array<string, true>>|null
     */
    public function cascadeFootprint(string $css, RouteSelectorMatchIndex $matchIndex, string $owner): ?array
    {
        if ( ! $this->isWellFormedStylesheet($css) ) {
            return null;
        }
        $units = array();
        $this->collectRuleUnits($css, array(), $units);
        $footprint = array();
        foreach ( $units as $unit ) {
            $families = self::cascadePropertyFamilies($unit['text']);
            if ( array() === $families ) {
                continue;
            }
            $targets = self::cascadeTargets($matchIndex, $owner, $unit['text']);
            if ( null === $targets ) {
                return null;
            }
            foreach ( array_keys($targets) as $id ) {
                $footprint[ $id ] = ( $footprint[ $id ] ?? array() ) + $families;
            }
        }
        return $footprint;
    }

    /**
     * Whether two cascade footprints set an overlapping family on one target.
     *
     * @param array<int|string, array<string, true>> $first
     * @param array<int|string, array<string, true>> $second
     */
    public static function cascadeFootprintsOverlap(array $first, array $second): bool
    {
        foreach ( array_intersect_key($first, $second) as $id => $families ) {
            if ( self::cascadeFamiliesOverlap($families, $second[ $id ]) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The page-document elements a rule unit may style, or null when that set
     * cannot be proven — an unreadable selector list or an arm the index
     * cannot evaluate — meaning cascade interaction cannot be disproved.
     *
     * @return array<int|string, true>|null
     */
    private static function cascadeTargets(RouteSelectorMatchIndex $matchIndex, string $owner, string $rule): ?array
    {
        // A shared rule recurs on every route: read its arms once per pass.
        $arms = CssRuleTreeCache::memo('cascade-target-arms', $rule, static function () use ($rule): array|int {
            // Keyframes compete only with keyframes of the same name.
            if ( 1 === preg_match('/^@(?:-[a-z]+-)?keyframes\s+([^\s{]+)\s*\{/i', ltrim(CssSyntaxScanner::withoutComments($rule)), $keyframes) ) {
                return array( 'keyframes' => '@keyframes ' . trim($keyframes[1], '"\'') );
            }
            return self::cascadeSelectorArms($rule) ?? 0;
        });
        if ( is_int($arms) ) {
            return null;
        }
        if ( isset($arms['keyframes']) ) {
            return array( $arms['keyframes'] => true );
        }
        $targets = array();
        foreach ( $arms as $arm ) {
            $matched = $matchIndex->matchedElements($arm, $owner);
            if ( null === $matched ) {
                return null;
            }
            $targets += $matched;
        }
        return $targets;
    }

    /**
     * The comment-free selector arms of a rule unit, or null when the rule's
     * selector list cannot be read, meaning interaction cannot be disproved.
     *
     * @return list<string>|null
     */
    private static function cascadeSelectorArms(string $rule): ?array
    {
        [, $rule] = self::splitLeadingComments($rule);
        $brace = strpos($rule, '{');
        if ( false === $brace ) {
            return null;
        }
        return self::splitSelectorList(trim(CssSyntaxScanner::withoutComments(substr($rule, 0, $brace))));
    }

    /**
     * Owners whose markup carries each page-local marker.
     *
     * @param array<string,string> $markup
     * @return array<string,array<string,true>>
     */
    private static function markerCarriers(array $markup): array
    {
        $carriers = array();
        foreach ($markup as $owner => $html) {
            // Each stylesheet path reads the same route markup.
            $markers = CssRuleTreeCache::memo('page-markers', $html, static function () use ($html): array {
                preg_match_all(CssSelectorListCompactor::PAGE_MARKER, $html, $matches);
                return array_values(array_unique($matches[0]));
            });
            foreach ($markers as $marker) $carriers[$marker][$owner] = true;
        }
        return $carriers;
    }

    /**
     * The page-local marker an arm requires: a literal `:where(.marker)` at
     * selector top level, outside every `:not()`/`:is()`/`:has()` argument.
     * Also accepts the literal richtext-marker style attribute form. Nested
     * alternatives, negations, comments and escapes do not prove a carrier.
     */
    private static function positiveMarker(string $arm): ?string
    {
        if (str_contains($arm, '/*') || str_contains($arm, '\\')) return null;
        $marker = '(blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+)';
        $pattern = '/:where\((?:\.' . $marker . '|mark\[style\*="--blocks-engine-richtext-marker:' . $marker . ';"\]|mark\[style\$="--blocks-engine-richtext-marker:' . $marker . '"\]|span\[data-blocks-engine-richtext-marker="' . $marker . '"\])\)/';
        if (!preg_match_all($pattern, $arm, $matches, PREG_OFFSET_CAPTURE)) return null;
        foreach ($matches[0] as $index => [, $offset]) {
            $before = substr($arm, 0, $offset);
            if (0 !== self::parenDepth($before) || substr_count($before, '[') !== substr_count($before, ']')) continue;
            foreach (array_slice($matches, 1) as $captures) if ('' !== ($captures[$index][0] ?? '')) return $captures[$index][0];
        }
        return null;
    }

    /** Required literal classes, never alternatives, negations or attributes. */
    private static function requiredPageMarkers(string $arm): array
    {
        $markers = array();
        if (str_contains($arm, '/*') || str_contains($arm, '\\')) return $markers;
        $positive = self::positiveMarker($arm);
        if (null !== $positive) $markers[$positive] = true;
        preg_match_all('/\.(blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+)(?![a-zA-Z0-9_-])/', $arm, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$marker, $offset]) {
            $before = substr($arm, 0, $offset - 1);
            if (0 === self::parenDepth($before) && substr_count($before, '[') === substr_count($before, ']')) $markers[$marker] = true;
        }
        return array_keys($markers);
    }

    /**
     * Reconcile ordered page projections at the source cascade position.
     * Unsupported pages are returned separately for the legacy copy path.
     *
     * Definition at-rules (`@keyframes`, `@font-face`, …) are inert unless a
     * declaration references them, so the ones only some routes keep or add
     * stay site-wide, once each. Two routes that define the same name with
     * different bodies would see each other's definition, so they fall back.
     * Leading @imports stay shared only when every route retains the exact
     * same ordered text; differing import lists use the copy fallback.
     *
     * @param list<string> $shared
     * @param array<string,list<string>> $pages
     * Aligned rules are expanded into arms, each with its own route set.
     * Arms proven inert outside that set stay unscoped; all others use a
     * base-excluding scope for variant-only arms, or a complementary negative
     * scope for arms retained by the virtual base document. Only validated lists
     * are split, preserving invalid-selector isolation and source order.
     *
     * @param array<string,string> $routeIdentities Route identities by compilation owner.
     * @param array<string,string> $markup Canonical markup by compilation owner.
     * @param array<string,string> $routeClasses Site-wide collision-free classes by route identity.
     * @param ?string $sharedMarkup Shared template-part markup; null means no carrier evidence.
     * @param ?callable(list<string>):string $routeSetClass Registers a set of route classes and returns its root class. Callers must materialize membership on every member root. Without it, standalone callers retain enumerated guards.
     * @param ?array<string,true> $rootTokens Every class and id some document root of the site can carry, captured or named by a script. A class- or pseudo-led arm is then forked into a root alternative only when its leading compound can match a root. Null keeps every root alternative.
     * `route_bytes` counts shipped growth after serialization and recompaction
     * over the unscoped arm-union sheet (including inserted arms).
     *
     * @return array{stylesheet:string, scoped:list<string>, reasons:array<string,string>, route_bytes:int}
     */
    public function scopeRouteVariants(array $shared, array $pages, array $routeIdentities = array(), ?string $unsafeReason = null, array $markup = array(), ?RouteSelectorMatchIndex $matchIndex = null, array $routeClasses = array(), ?string $sharedMarkup = null, ?callable $routeSetClass = null, ?array $rootTokens = null): array
    {
        $base = array();
        foreach ($shared as $css) $this->collectRuleUnits($css, array(), $base);
        $base = self::withoutIgnoredImports($base);
        $baseImports = self::routeImports($base);
        // Byte-dependent facts live only for this reconciliation, not per route.
        $normals = $keys = $probes = $armLists = $parsedArms = array();
        $normal = static function (array $unit) use (&$normals): string {
            return $normals[$unit['text']] ??= trim(CssSyntaxScanner::withoutComments($unit['text']));
        };
        $key = function (array $unit) use ($normal, &$keys): string {
            $id = serialize(array($unit['context'], $unit['text']));
            if (isset($keys[$id])) return $keys[$id];
            $rule = self::splitLeadingComments($unit['text'])[1];
            $brace = $this->nextRuleBoundary($rule, 0);
            $selector = null !== $brace && '{' === $rule[$brace] && !$this->isAtRule($rule);
            return $keys[$id] = serialize(array($unit['context'], $selector ? substr($rule, $brace) : $normal($unit)));
        };
        $value = static fn (array $unit): string => serialize(array($unit['context'], $normal($unit)));
        $baseDefinitions = $defined = $baseValues = array();
        $baseKeys = array_map($key, $base);
        foreach ($base as $i => $unit) {
            $baseDefinitions[$i] = $this->routeIndependentDefinition($unit);
            if (null === $baseDefinitions[$i]) continue;
            $defined[$baseDefinitions[$i]][$value($unit)] = true;
            $baseValues[$value($unit)] = true;
        }
        $probe = function (array $unit, ?string &$reason) use (&$probes, &$parsedArms): void {
            // A prior failure affects functional-root validation's early exit.
            if (null !== $reason) { $this->probeRouteUnit($unit, $reason, $parsedArms); return; }
            $id = serialize(array($unit['text'], $unit['dedupable']));
            if (!array_key_exists($id, $probes)) {
                $failure = null;
                $this->probeRouteUnit($unit, $failure, $parsedArms);
                $probes[$id] = $failure;
            }
            if (null !== $probes[$id]) $reason = $probes[$id];
        };
        $sheetReasons = array();
        $checkSheet = function (string $css, ?string &$reason) use (&$sheetReasons): void {
            if (!array_key_exists($css, $sheetReasons)) {
                $sheetReasons[$css] = $this->isWellFormedStylesheet($css) ? null : 'malformed stylesheet';
                if (preg_match('/@namespace\b/i', $css)) $sheetReasons[$css] = '@namespace';
            }
            if (null !== $sheetReasons[$css]) $reason = $sheetReasons[$css];
        };
        $sharedReason = $unsafeReason;
        foreach ($shared as $css) $checkSheet($css, $sharedReason);
        $changes = $insertions = $siteInsertions = $reasons = array();
        $scoped = array();
        foreach ($pages as $owner => $sheets) {
            $units = array();
            $reason = $sharedReason;
            foreach ($sheets as $css) $checkSheet($css, $reason);
            if (null !== $reason) { $reasons[$owner] = $reason; continue; }
            foreach ($sheets as $css) $this->collectRuleUnits($css, array(), $units);
            $units = self::withoutIgnoredImports($units);
            if (null === $baseImports || self::routeImports($units) !== $baseImports) {
                $reasons[$owner] = 'different or non-leading @import rules';
                continue;
            }
            $cursor = 0;
            $localChanges = $localInsertions = $localSite = array();
            $matches = array();
            // Anchor unchanged rules before aligning declaration-only keys.
            // Otherwise an inserted rule with a common body (outline:none,
            // for example) consumes the next unchanged root/body rule and
            // shifts the whole run into complementary route forks.
            $exact = array();
            $unitKeys = array_map($key, $units);
            self::alignKeys(array_map($value, $units), array_map($value, $base), 0, count($units), 0, count($base), $exact);
            ksort($exact);
            $routeCursor = $baseCursor = 0;
            foreach ($exact + array(count($units) => count($base)) as $j => $i) {
                self::alignKeys($unitKeys, $baseKeys, $routeCursor, $j, $baseCursor, $i, $matches);
                if ($j < count($units)) $matches[$j] = $i;
                $routeCursor = $j + 1;
                $baseCursor = $i + 1;
            }
            foreach ($units as $j => $unit) {
                $match = $matches[$j] ?? null;
                if (null === $match) {
                    if (null !== $this->routeIndependentDefinition($unit)) $localSite[$cursor][] = $unit;
                    else $localInsertions[$cursor][] = $unit;
                    continue;
                }
                while ($cursor < $match) $localChanges[$cursor++] = null;
                if ($normal($unit) !== $normal($base[$match])) $localChanges[$match] = $unit;
                $cursor = $match + 1;
            }
            while ($cursor < count($base)) $localChanges[$cursor++] = null;
            // A definition the route drops or adds stays site-wide, unless the
            // route would then see a different body under the same name.
            $pageDefined = $defined;
            $touched = array();
            foreach ($localChanges as $i => $variant) {
                if (null === $baseDefinitions[$i]) continue;
                $touched[$baseDefinitions[$i]] = true;
                unset($localChanges[$i]);
            }
            foreach ($localSite as $list) foreach ($list as $unit) {
                $name = (string) $this->routeIndependentDefinition($unit);
                $touched[$name] = true;
                $pageDefined[$name][$value($unit)] = true;
            }
            foreach (array_keys($touched) as $name) {
                if (count($pageDefined[$name] ?? array()) > 1) { $reason = 'conflicting ' . $name; break; }
            }
            // Validate every affected selector before committing any route edits.
            foreach ($localChanges as $i => $variant) {
                foreach (array_filter(array($base[$i], $variant)) as $unit) {
                    $probe($unit, $reason);
                }
            }
            foreach ($localInsertions as $list) foreach ($list as $unit) {
                $probe($unit, $reason);
            }
            if (null !== $reason) { $reasons[$owner] = $reason; continue; }
            $scoped[] = (string) $owner;
            $defined = $pageDefined;
            foreach ($localChanges as $i => $variant) $changes[$i][$owner] = $variant;
            foreach ($localInsertions as $i => $list) $insertions[$i][$owner] = $list;
            foreach ($localSite as $i => $list) foreach ($list as $unit) {
                if (isset($baseValues[$value($unit)])) continue;
                $baseValues[$value($unit)] = true;
                $siteInsertions[$i][] = $unit;
            }
        }
        $output = array();
        $identities = array();
        foreach (array_keys($pages) as $owner) $identities[] = $routeIdentities[$owner] ?? (string) $owner;
        $routeClasses = $routeClasses ?: \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClasses($identities);
        $routeClass = static fn ($owner): string => $routeClasses[$routeIdentities[$owner] ?? (string) $owner];
        $unionOutput = array();
        $allRoutes = array_fill_keys(array_keys($pages), true);
        // Non-plan content is unknown, but imported engine classes can only
        // come from shared template parts. Never run a full matcher here.
        $baseOwner = '';
        while (isset($allRoutes[$baseOwner])) $baseOwner .= '#';
        $allRoutes[$baseOwner] = true;
        if (null !== $sharedMarkup) $markup[$baseOwner] = $sharedMarkup;
        $carriers = self::markerCarriers($markup);
        // Adjacent contributions with one context and byte-identical
        // declarations are scoped together: an arm's gate is the union of
        // the routes that carry it across the run, so complementary route
        // sets collapse to one ungated arm. Order within such a run cannot
        // change any cascade result.
        $pending = null;
        $flush = function () use (&$pending, &$output, &$parsedArms, $routeClass, $routeSetClass, $allRoutes, $baseOwner, $carriers, $markup, $matchIndex, $rootTokens): void {
            if (null === $pending) return;
            ['members' => $members, 'prototype' => $prototype, 'lead' => $lead, 'body' => $body] = $pending;
            $pending = null;
            $groups = array();
            foreach ($members as $arm => $routes) {
                $outside = array_diff_key($allRoutes, $routes);
                $inert = !$outside;
                if (!$inert && !RouteSelectorMatchIndex::dependsOnRouteState($arm)) {
                    $unproven = $outside;
                    foreach (self::requiredPageMarkers($arm) as $marker) {
                        // A missing required carrier disproves the arm on that
                        // document, including required ancestors and subjects.
                        foreach ($unproven as $owner => $_) {
                            if (isset($markup[$owner]) && !isset($carriers[$marker][$owner])) unset($unproven[$owner]);
                        }
                    }
                    $inert = !$unproven;
                    if (!$inert && !isset($unproven[$baseOwner]) && null !== $matchIndex) $inert = $matchIndex->inertOutside($arm, $unproven);
                }
                $scope = '';
                if (!$inert) {
                    if (null !== $routeSetClass) {
                        // The virtual base includes unknown, non-plan documents.
                        // Preserve that open-world complement with a negative set.
                        $negative = isset($routes[$baseOwner]);
                        $owners = array_keys($negative ? $outside : $routes);
                        $classes = array_map($routeClass, $owners);
                        // Singleton sets already have a materialized identity.
                        // Retain the compact legacy form for their complements too.
                        $class = 1 === count($classes) ? $classes[0] : $routeSetClass($classes);
                        $scope = ':where(:root' . ($negative ? ':not(.' . $class . ')' : (1 === count($classes) ? ':is(.' . $class . ')' : '.' . $class)) . ')';
                    } elseif (isset($routes[$baseOwner])) {
                        $scope = ':where(:root:not(' . implode(',', array_map(static fn ($owner): string => '.' . $routeClass($owner), array_keys($outside))) . '))';
                    } else {
                        $positive = ':where(:root:is(' . implode(',', array_map(static fn ($owner): string => '.' . $routeClass($owner), array_keys($routes))) . '))';
                        unset($outside[$baseOwner]);
                        // The exact marker excludes non-plan documents even when
                        // every plan route keeps this variant-only arm.
                        $negative = ':where(:root.be-r' . ($outside ? ':not(' . implode(',', array_map(static fn ($owner): string => '.' . $routeClass($owner), array_keys($outside))) . ')' : '') . ')';
                        $scope = strlen($positive) <= strlen($negative) ? $positive : $negative;
                    }
                }
                $groups[$scope][] = $arm;
            }
            // A gated arm that differs from an ungated arm of this run only
            // by a zero-weight exclusion matches a subset of it with the same
            // declarations and weight, so it changes nothing.
            $ungated = array_fill_keys($groups[''] ?? array(), true);
            foreach ($groups as $scope => $arms) {
                if ('' === $scope || array() === $ungated) continue;
                $groups[$scope] = array_values(array_filter($arms, static fn (string $arm): bool => !isset($ungated[self::withoutTrailingZeroWeightExclusion($arm)])));
                if (array() === $groups[$scope]) unset($groups[$scope]);
            }
            foreach ($groups as $scope => $arms) {
                $unit = $prototype;
                if ('' !== $scope) {
                    $reason = null;
                    $arms = null === $routeSetClass || !str_contains($scope, '.be-rs-')
                        ? self::scopeRouteArms($arms, $scope, $reason, $parsedArms, $rootTokens)
                        : self::scopeRouteSubjects($arms, $scope, $reason, $parsedArms);
                }
                $unit['text'] = $lead . implode(',', $arms) . $body;
                $output[] = $unit;
                $lead = '';
            }
        };
        $emitArms = function (array $units, array $prototype) use (&$pending, &$unionOutput, &$armLists, $flush): void {
            [$lead, $rule] = self::splitLeadingComments($prototype['text']);
            $brace = (int) $this->nextRuleBoundary($rule, 0);
            $body = substr($rule, $brace);
            $members = array();
            foreach ($units as $owner => $unit) {
                if (null === $unit) continue;
                $text = $unit['text'];
                if (!isset($armLists[$text])) {
                    [, $rule] = self::splitLeadingComments($text);
                    $start = (int) $this->nextRuleBoundary($rule, 0);
                    $armLists[$text] = (array) self::splitSelectorList(CssSelectorListCompactor::expand(CssSyntaxScanner::withoutComments(substr($rule, 0, $start))));
                }
                foreach ($armLists[$text] as $arm) {
                    $members[trim($arm)][$owner] = true;
                }
            }
            $union = $prototype;
            $union['text'] = $lead . implode(',', array_keys($members)) . $body;
            $unionOutput[] = $union;
            if (null !== $pending && '' === $lead && $pending['prototype']['context'] === $prototype['context'] && $pending['body'] === $body) {
                foreach ($members as $arm => $routes) $pending['members'][$arm] = ($pending['members'][$arm] ?? array()) + $routes;
                return;
            }
            $flush();
            $pending = array('members' => $members, 'prototype' => $prototype, 'lead' => $lead, 'body' => $body);
        };
        for ($i = 0; $i <= count($base); ++$i) {
            if (isset($siteInsertions[$i])) $flush();
            foreach ($siteInsertions[$i] ?? array() as $unit) { $output[] = $unit; $unionOutput[] = $unit; }
            $groups = array();
            foreach ($insertions[$i] ?? array() as $owner => $units) foreach ($units as $ordinal => $unit) {
                $groupKey = serialize(array($ordinal, $key($unit)));
                $groups[$groupKey]['ordinal'] = $ordinal;
                $groups[$groupKey]['unit'] = $unit;
                $groups[$groupKey]['routes'][$owner] = $unit;
            }
            uasort($groups, static fn (array $a, array $b): int => $a['ordinal'] <=> $b['ordinal']);
            foreach ($groups as $group) $emitArms($group['routes'], $group['unit']);
            if ($i === count($base)) break;
            if (empty($changes[$i])) { $flush(); $output[] = $base[$i]; $unionOutput[] = $base[$i]; continue; }
            $units = array();
            foreach ($allRoutes as $owner => $_) $units[$owner] = array_key_exists($owner, $changes[$i]) ? $changes[$i][$owner] : $base[$i];
            $emitArms($units, $base[$i]);
        }
        $flush();
        $stylesheet = $this->serializeRuleUnits($output);
        // Match ArtifactCompiler's final serialization, not the expanded arms:
        // identical scope prefixes can ship once for an entire selector group.
        $recompact = static fn (string $css): string => count($pages) > 1 ? CssSelectorListCompactor::stylesheet($css, true) : $css;
        $routeBytes = max(0, strlen($recompact($stylesheet)) - strlen($recompact($this->serializeRuleUnits($unionOutput))));
        return array('stylesheet' => $stylesheet, 'scoped' => $scoped, 'reasons' => $reasons, 'route_bytes' => $routeBytes);
    }

    /**
     * Imports must match byte-for-byte, in order, at the sheet top. Unlike
     * definitions they cannot be added or dropped independently by a route.
     *
     * @param list<array{context:list<string>,text:string,dedupable:bool}> $units
     * @return list<string>|null
     */
    private static function routeImports(array $units): ?array
    {
        $imports = array();
        $top = true;
        foreach ($units as $unit) {
            [, $rule] = self::splitLeadingComments($unit['text']);
            if (preg_match('/^@import\b/i', $rule)) {
                if (!$top || $unit['context']) return null;
                $imports[] = $unit['text'];
            } elseif ('' !== trim($rule) && !preg_match('/^@charset\b/i', $rule)) {
                $top = false;
            }
        }
        return $imports;
    }

    /**
     * Align a route's unit keys with the shared ones by patience diff: match the
     * common prefix and suffix, anchor on the longest ordered run of keys unique
     * to both windows, and recurse between anchors. A key repeated later in the
     * shared sheet can no longer pull every intervening unit out of alignment.
     *
     * @param list<string>     $route
     * @param list<string>     $base
     * @param array<int, int>  $matches Route index => shared index.
     */
    private static function alignKeys(array $route, array $base, int $from, int $to, int $baseFrom, int $baseTo, array &$matches): void
    {
        while ($from < $to && $baseFrom < $baseTo && $route[$from] === $base[$baseFrom]) $matches[$from++] = $baseFrom++;
        while ($from < $to && $baseFrom < $baseTo && $route[$to - 1] === $base[$baseTo - 1]) $matches[--$to] = --$baseTo;
        if ($from >= $to || $baseFrom >= $baseTo) return;
        $routeCounts = $baseCounts = $basePositions = array();
        for ($j = $from; $j < $to; $j++) $routeCounts[$route[$j]] = ($routeCounts[$route[$j]] ?? 0) + 1;
        for ($i = $baseFrom; $i < $baseTo; $i++) {
            $baseCounts[$base[$i]] = ($baseCounts[$base[$i]] ?? 0) + 1;
            $basePositions[$base[$i]] = $i;
        }
        $anchors = array();
        for ($j = $from; $j < $to; $j++) {
            if (1 === $routeCounts[$route[$j]] && 1 === ($baseCounts[$route[$j]] ?? 0)) $anchors[] = array($j, $basePositions[$route[$j]]);
        }
        if (!$anchors) {
            $cursor = $baseFrom;
            for ($j = $from; $j < $to; $j++) {
                for ($i = $cursor; $i < $baseTo; $i++) {
                    if ($route[$j] === $base[$i]) { $matches[$j] = $i; $cursor = $i + 1; break; }
                }
            }
            return;
        }
        // Longest increasing run of shared positions (patience sorting).
        $tails = $previous = array();
        foreach ($anchors as $a => [, $position]) {
            $low = 0;
            $high = count($tails);
            while ($low < $high) {
                $middle = ($low + $high) >> 1;
                if ($anchors[$tails[$middle]][1] < $position) $low = $middle + 1;
                else $high = $middle;
            }
            $previous[$a] = $low > 0 ? $tails[$low - 1] : -1;
            $tails[$low] = $a;
        }
        $chain = array();
        for ($a = (int) end($tails); $a >= 0; $a = $previous[$a]) $chain[] = $anchors[$a];
        foreach (array_reverse($chain) as [$j, $i]) {
            self::alignKeys($route, $base, $from, $j, $baseFrom, $i, $matches);
            $matches[$j] = $i;
            $from = $j + 1;
            $baseFrom = $i + 1;
        }
        self::alignKeys($route, $base, $from, $to, $baseFrom, $baseTo, $matches);
    }

    private static function withoutIgnoredImports(array $units): array
    {
        $top = true;
        $kept = array();
        foreach ($units as $unit) {
            [, $rule] = self::splitLeadingComments($unit['text']);
            if (preg_match('/^@import\b/i', $rule) && !$unit['context']) {
                if ($top) $kept[] = $unit;
                continue;
            }
            if ('' !== trim($rule) && !preg_match('/^@charset\b/i', $rule)) $top = false;
            $kept[] = $unit;
        }
        return $kept;
    }

    /**
     * The name a route-independent definition at-rule binds, or null for any
     * other unit. Same-name definitions with different bodies conflict.
     *
     * @param array{context:list<string>,text:string,dedupable:bool} $unit
     */
    private function routeIndependentDefinition(array $unit): ?string
    {
        $rule = trim(CssSyntaxScanner::withoutComments($unit['text']));
        if (!$this->isAtRule($rule)) return null;
        $name = self::atRuleName($rule);
        // A late @charset is ignored; dedupe it by text, never conflict.
        if ('charset' === $name) return '@charset ' . $rule;
        // A layer order statement names layers the same way on every route.
        if ('layer' === $name && str_ends_with($rule, ';')) return '@layer ' . $rule;
        $brace = $this->nextRuleBoundary($rule, 0);
        if (null === $brace || '{' !== $rule[$brace] || !str_ends_with($rule, '}')) return null;
        $prelude = trim(substr($rule, strlen($name) + 1, $brace - strlen($name) - 1));
        if (preg_match('/^(?:-[a-z]+-)?keyframes$/', $name)) return '@keyframes ' . trim($prelude, '"\'');
        if (in_array($name, array('counter-style', 'property', 'font-palette-values'), true)) return '@' . $name . ' ' . $prelude;
        if ('font-face' !== $name) return null;
        $descriptors = array();
        foreach (CssRuleAnalyzer::declarations(substr($rule, $brace + 1, -1), array('font-family', 'font-style', 'font-weight', 'font-stretch', 'unicode-range')) as $declaration) {
            $descriptors[$declaration['name']] = strtolower(trim($declaration['value'], '"\' '));
        }
        ksort($descriptors);
        return '@font-face ' . json_encode($descriptors);
    }

    /**
     * Split a unit into the comments before it and the rule itself.
     *
     * @return array{0:string,1:string}
     */
    private static function splitLeadingComments(string $text): array
    {
        $offset = 0;
        $length = strlen($text);
        while (true) {
            while ($offset < $length && CssSyntaxScanner::isCssWhitespace($text[$offset])) ++$offset;
            if (!str_starts_with(substr($text, $offset, 2), '/*')) break;
            $close = strpos($text, '*/', $offset + 2);
            if (false === $close) break;
            $offset = $close + 2;
        }
        return array(substr($text, 0, $offset), substr($text, $offset));
    }

    /** @param array{context:list<string>,text:string,dedupable:bool} $unit */
    private function probeRouteUnit(array $unit, ?string &$reason, array &$parsedArms): void
    {
        [, $rule] = self::splitLeadingComments($unit['text']);
        $brace = $this->nextRuleBoundary($rule, 0);
        if (null === $brace || '{' !== $rule[$brace] || $this->isAtRule($rule) || !$unit['dedupable']) { $reason = 'non-selector rule cannot be route scoped'; return; }
        $arms = self::splitSelectorList(trim(CssSyntaxScanner::withoutComments(substr($rule, 0, $brace))));
        if (null === $arms) { $reason = 'unparseable selector list'; return; }
        self::scopeRouteArms($arms, ':where(:root:is(.be-route-probe))', $reason, $parsedArms);
    }

    /** @param list<string> $arms @return list<string> */
    private static function scopeRouteSubjects(array $arms, string $scope, ?string &$reason, array &$parsedArms): array
    {
        // Test document membership on the subject, not on an assumed ancestor.
        // A class-led first compound can be either the root or a descendant;
        // prefixing the scope forks it into two arms for no semantic reason.
        // This zero-weight predicate covers both cases once, retaining every
        // authored compound, combinator and specificity (including :is()).
        $root = substr($scope, strlen(':where('), -1);
        $guard = ':where(' . $root . ',' . $root . ' *)';
        foreach ($arms as &$arm) {
            $tokens = CssSelectorTokenizer::tokenize($arm);
            $first = $tokens['compounds'][0];
            // Definite root anchors can merge the scope (including inside a
            // functional root arm); definite non-root types use an ancestor.
            // Both forms retain prefix factoring across selector groups.
            if (!preg_match('/^[*.#\[:]/', $first) || preg_match('/(?<![\w-])(?:html|:root)\b/i', $first)) {
                $scoped = self::scopeRouteArms(array($arm), $scope, $reason, $parsedArms);
                if (null !== $scoped) {
                    $arm = implode(',', $scoped);
                    continue;
                }
            }
            $last = count($tokens['compounds']) - 1;
            $span = $tokens['compound_spans'][$last];
            $pseudo = self::pseudoElementOffset($tokens['compounds'][$last]);
            $insert = null === $pseudo ? $span['end'] : $span['start'] + $pseudo;
            $arm = substr($arm, 0, $insert) . $guard . substr($arm, $insert);
        }
        unset($arm);
        return $arms;
    }

    /** @param array<string,true>|null $rootTokens Classes and ids any document root can carry; null when unknown. */
    private static function scopeRouteArms(array $arms, string $scope, ?string &$reason, array &$parsedArms, ?array $rootTokens = null): ?array
    {
        $rootAlternatives = array();
        foreach ($arms as &$arm) {
            $arm = trim($arm);
            if (!array_key_exists($arm, $parsedArms)) {
                $tokens = CssSelectorTokenizer::tokenize($arm);
                $structural = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\[[^\]]*\]/s', '', $arm) ?? $arm;
                $parsedArms[$arm] = null;
                if ($tokens['supported'] && !str_contains($structural, '&') && !preg_match('/(?<!\|)\|(?!\|)/', $structural)) {
                    $first = $tokens['compounds'][0];
                    $end = $tokens['compound_spans'][0]['end'];
                    // A pseudo-element ends its compound: merge before it.
                    $insert = 1 === count($tokens['compounds']) ? (self::pseudoElementOffset($first) ?? $end) : $end;
                    $parsedArms[$arm] = array($first, $end, $insert);
                }
            }
            if (null === $parsedArms[$arm]) { $reason = 'unparseable or relative selector'; return null; }
            [$first, $end, $insert] = $parsedArms[$arm];
            if (preg_match('/^(?:html(?=[.#:\[]|$)|:root(?=[.#:\[]|$))/i', $first)) {
                $arm = substr($arm, 0, $insert) . $scope . substr($arm, $insert);
                continue;
            }
            $functional = preg_match('/(?<![\w-])(?:html|:root)\b/i', $first) ? self::scopeFunctionalRoot($first, $scope, $reason, $parsedArms, $rootTokens) : null;
            if (null !== $reason) return null;
            if (null !== $functional) {
                $arm = $functional . substr($arm, $end);
                continue;
            }
            // Class/universal/attribute/pseudo arms may themselves match html.
            // Retain that match as well as descendants without adding weight,
            // unless the leading compound requires a type, class or id no
            // document root carries: that alternative can never match.
            if (preg_match('/^[*.#\[:]/', $first) && (null === $rootTokens || self::compoundMayMatchRoot($first, $rootTokens))) {
                $rootAlternatives[] = substr($arm, 0, $insert) . $scope . substr($arm, $insert);
            }
            $arm = $scope . ' ' . $arm;
        }
        unset($arm);
        return array_merge($arms, $rootAlternatives);
    }

    /**
     * Scope the first `:is()`/`:where()` argument list in $compound that
     * tests the root, or null when the root test sits elsewhere (`:not()`,
     * `:has()`), where an ancestor scope is already exact.
     */
    private static function scopeFunctionalRoot(string $compound, string $scope, ?string &$reason, array &$parsedArms, ?array $rootTokens = null): ?string
    {
        if (!preg_match_all('/:(?:is|where|matches|-webkit-any|-moz-any)\(/i', $compound, $matches, PREG_OFFSET_CAPTURE)) return null;
        foreach ($matches[0] as [$open, $offset]) {
            $start = $offset + strlen($open);
            if (0 !== self::parenDepth(substr($compound, 0, $offset))) continue;
            $close = self::closingParen($compound, $start - 1);
            if (null === $close) continue;
            $argument = substr($compound, $start, $close - $start);
            if (!preg_match('/(?<![\w-])(?:html|:root)\b/i', $argument)) continue;
            $alternatives = self::splitSelectorList($argument);
            if (null === $alternatives) { $reason = 'unparseable functional root selector'; return null; }
            $scoped = self::scopeRouteArms($alternatives, $scope, $reason, $parsedArms, $rootTokens);
            if (null === $scoped) return null;
            return substr($compound, 0, $start) . implode(',', $scoped) . substr($compound, $close);
        }
        return null;
    }

    /** Class names WordPress, its editor or the engine may put on a document root. */
    private const ROOT_MINTED_CLASS = '/^(?:wp-|is-|has-|blocks-engine-|be-|route-class-|editor-|block-editor-|components-)/';

    /**
     * Whether a compound could match a document root, given every class and
     * id some root carries. Classes WordPress, the editor or the engine mint
     * (route classes and root-owned state markers among them) may sit on any
     * root. A leading type other than html, a required class or id absent
     * from every root, or a positive `:is()`/`:where()` whose every alternative is such
     * a compound disproves the match. Negations, `:has()`, attributes,
     * escapes and complex alternatives prove nothing and keep it possible.
     *
     * @param array<string,true> $rootTokens
     */
    private static function compoundMayMatchRoot(string $compound, array $rootTokens): bool
    {
        if (str_contains($compound, '\\') || str_contains($compound, '|') || 1 === preg_match('/[^\x20-\x7E]/', $compound)) return true;
        $length = strlen($compound);
        if (preg_match('/^[A-Za-z][\w-]*/', $compound, $type) && 'html' !== strtolower($type[0])) return false;
        for ($i = 0; $i < $length; ++$i) {
            $char = $compound[$i];
            if ('"' === $char || "'" === $char) {
                $close = strpos($compound, $char, $i + 1);
                if (false === $close) return true;
                $i = $close;
            } elseif ('[' === $char || '(' === $char) {
                $close = self::closingBracket($compound, $i);
                if (null === $close) return true;
                $i = $close;
            } elseif (('.' === $char || '#' === $char) && preg_match('/\G[\w-]+/', $compound, $name, 0, $i + 1)) {
                if (!isset($rootTokens[$name[0]]) && !('.' === $char && 1 === preg_match(self::ROOT_MINTED_CLASS, $name[0]))) return false;
                $i += strlen($name[0]);
            } elseif (':' === $char && preg_match('/\G:(?:is|where|matches|-webkit-any|-moz-any)\(/i', $compound, $open, 0, $i)) {
                $start = $i + strlen($open[0]);
                $close = self::closingBracket($compound, $start - 1);
                if (null === $close) return true;
                $alternatives = self::splitSelectorList(substr($compound, $start, $close - $start));
                if (null !== $alternatives && array() !== $alternatives) {
                    $possible = false;
                    foreach ($alternatives as $alternative) {
                        $tokens = CssSelectorTokenizer::tokenize(trim($alternative));
                        if (!$tokens['supported'] || 1 !== count($tokens['compounds']) || self::compoundMayMatchRoot($tokens['compounds'][0], $rootTokens)) { $possible = true; break; }
                    }
                    if (!$possible) return false;
                }
                $i = $close;
            } elseif (':' === $char && preg_match('/\G::?[\w-]+/', $compound, $pseudo, 0, $i)) {
                $i += strlen($pseudo[0]) - 1;
            }
        }
        return true;
    }

    /** Offset of the bracket or parenthesis closing the one at $open, outside strings. */
    private static function closingBracket(string $text, int $open): ?int
    {
        $stack = array();
        $quote = null;
        for ($i = $open, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if (null !== $quote) {
                if ('\\' === $char) ++$i;
                elseif ($char === $quote) $quote = null;
                continue;
            }
            if ('"' === $char || "'" === $char) $quote = $char;
            elseif ('(' === $char) $stack[] = ')';
            elseif ('[' === $char) $stack[] = ']';
            elseif (')' === $char || ']' === $char) {
                if (array_pop($stack) !== $char) return null;
                if (array() === $stack) return $i;
            }
        }
        return null;
    }

    /** Byte offset of a top-level pseudo-element in a compound, if any. */
    private static function pseudoElementOffset(string $compound): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($compound);
        for ($offset = 0; $offset < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($compound, $offset, $state);
            if (null === $next) return null;
            if ($topLevel && $next === $offset + 1 && ':' === $compound[$offset]) {
                if (':' === ($compound[$offset + 1] ?? '') || preg_match('/\G:(?:before|after|first-line|first-letter)(?![\w-])/i', $compound, $_, 0, $offset)) return $offset;
            }
            $offset = $next;
        }
        return null;
    }

    private static function parenDepth(string $value): int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = 0; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if (null === $next) return -1;
            $offset = $next;
        }
        return $state['parens'];
    }

    private static function closingParen(string $value, int $open): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = $open; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if (null === $next) return null;
            if ($next === $offset + 1 && ')' === $value[$offset] && 0 === $state['parens'] && '' === $state['quote'] && !$state['comment']) return $offset;
            $offset = $next;
        }
        return null;
    }

    /**
     * The cascade families a rule unit declares: a property, without vendor
     * prefix, reduced to the shorthand that also sets it, so a shorthand and
     * its longhands share a family. Custom properties are their own family.
     * A statement without a block declares nothing. A rule whose declarations
     * cannot be read (or that sets `all`) reports the wildcard family, which
     * overlaps every other.
     *
     * @return array<string, true>
     */
    private static function cascadePropertyFamilies(string $rule): array
    {
        return array_fill_keys(CssRuleTreeCache::memo('cascade-property-families', $rule, static fn (): array => array_map('strval', array_keys(self::parseCascadePropertyFamilies($rule)))), true);
    }

    /** @return array<string, true> */
    private static function parseCascadePropertyFamilies(string $rule): array
    {
        $brace = strpos($rule, '{');
        if ( false !== $brace && '' === trim(substr(trim($rule), $brace + 1, -1)) ) {
            return array();
        }
        if ( false === $brace ) {
            // A statement — @charset, @import, @namespace, a layer list —
            // declares no property, so it competes with no rule.
            return array();
        }
        if ( ! str_ends_with(trim($rule), '}') || ! preg_match_all('/(?:^|[;{}])\\s*(-{0,2}[a-zA-Z_][a-zA-Z0-9_-]*)\\s*:/', substr($rule, $brace + 1), $matches) ) {
            return array( '*' => true );
        }
        $aliases = array( 'top' => 'inset', 'right' => 'inset', 'bottom' => 'inset', 'left' => 'inset', 'row' => 'gap', 'column' => 'gap', 'align' => 'place', 'justify' => 'place', 'inline' => 'size', 'block' => 'size', 'width' => 'size', 'height' => 'size', 'min' => 'size', 'max' => 'size', 'line' => 'font', 'columns' => 'gap' );
        $families = array();
        foreach ( $matches[1] as $property ) {
            $property = strtolower($property);
            if ( str_starts_with($property, '--') ) {
                $families[ $property ] = true;
                continue;
            }
            $root = explode('-', (string) preg_replace('/^-(?:webkit|moz|ms|o)-/', '', $property))[0];
            $families[ 'all' === $root ? '*' : ( $aliases[ $root ] ?? $root ) ] = true;
        }
        return $families;
    }

    /** @param array<string, true> $families @param array<string, true> $kept */
    private static function cascadeFamiliesOverlap(array $families, array $kept): bool
    {
        return array() !== $families && array() !== $kept && ( isset($families['*']) || isset($kept['*']) || array() !== array_intersect_key($families, $kept) );
    }

    /** @param array{context: list<string>, text: string, dedupable: bool} $unit */
    private static function ruleUnitKey(array $unit): string
    {
        $source = serialize(array( $unit['context'], $unit['text'] ));
        return (string) CssRuleTreeCache::memo('rule-unit-key', $source, static fn (): array => array( self::parseRuleUnitKey($unit) ))[0];
    }

    /** @param array{context: list<string>, text: string, dedupable: bool} $unit */
    private static function parseRuleUnitKey(array $unit): string
    {
        return md5(implode("\0", array_map(self::normalizedCss(...), $unit['context'])) . "\0" . self::normalizedCss($unit['text']), true);
    }

    /** The arm without a final `:not(:where(...))` (zero specificity), or the arm itself. */
    private static function withoutTrailingZeroWeightExclusion(string $arm): string
    {
        if (!str_ends_with($arm, '))')) return $arm;
        $depth = 0;
        for ($index = strlen($arm) - 1; $index >= 0; --$index) {
            if (')' === $arm[$index]) ++$depth;
            elseif ('(' === $arm[$index] && 0 === --$depth) {
                $start = $index - 4;
                return $start > 0 && str_starts_with(substr($arm, $start), ':not(:where(') ? substr($arm, 0, $start) : $arm;
            }
        }
        return $arm;
    }

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
        $this->collectTreeUnits($this->tree($css), -1, $context, $units);
    }

    private function collectTreeUnits(CssRuleTree $tree, int $scope, array $context, array &$units): void
    {
        $css = $tree->source;
        $offset = $scope + 1;
        foreach ($tree->rules($scope) as $rule) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            $prelude = substr($css, $start, $boundary - $start);
            $atRule = $this->isAtRule($prelude) ? self::atRuleName($prelude) : '';
            $layerPrelude = 'layer' === $atRule ? trim(CssSyntaxScanner::withoutComments($prelude)) : '';
            $anonymousLayer = 'layer' === $atRule && '' === trim(substr($layerPrelude, strlen('@layer')));
            if ('{' === $css[$boundary] && '' !== $atRule && $this->walksNestedRules($prelude) && !$anonymousLayer) {
                $nested = $context;
                $nested[] = trim($prelude);
                $this->collectTreeUnits($tree, $boundary, $nested, $units);
            } else {
                $units[] = array('context' => $context, 'text' => substr($css, $start, $end - $start + 1), 'dedupable' => !in_array($atRule, array('charset', 'import', 'layer', 'namespace'), true));
            }
            $offset = $end + 1;
        }
        $limit = -1 === $scope ? strlen($css) : $tree->blockEnd($scope);
        $tail = substr($css, $offset, $limit - $offset);
        // Commented-out rules inside a group carry nothing a route could scope.
        if ('' !== trim(array() === $context ? $tail : CssSyntaxScanner::withoutComments($tail))) $units[] = array('context' => $context, 'text' => $tail, 'dedupable' => false);
    }

    /**
     * @return array{preamble: string, stylesheet: string, statements: list<string>}
     */
    public function splitLeadingAtRulePreamble(string $stylesheet): array
    {
        $offset = 0;
        $length = strlen($stylesheet);
        $statements = array();
        while ( $offset < $length ) {
            $boundary = $this->nextRuleBoundary($stylesheet, $offset);
            if ( null === $boundary || ';' !== $stylesheet[ $boundary ] ) {
                break;
            }

            $statement = substr($stylesheet, $offset, $boundary - $offset + 1);
            if ( ! in_array(self::atRuleName($statement), array( 'charset', 'import', 'namespace', 'layer' ), true) ) {
                break;
            }
            $statements[] = $statement;
            $offset = $boundary + 1;
        }

        return array(
            'preamble'   => substr($stylesheet, 0, $offset),
            'stylesheet' => substr($stylesheet, $offset),
            'statements' => $statements,
        );
    }

    /**
     * Split a selector list only at top-level commas. Null indicates malformed CSS.
     *
     * @return list<string>|null
     */
    public static function splitSelectorList(string $prelude): ?array
    {
        $result = CssRuleTreeCache::memo('selectors', $prelude, static fn (): array|int => self::splitSelectorListUncached($prelude) ?? 0);
        return is_array($result) ? $result : null;
    }

    private static function splitSelectorListUncached(string $prelude): ?array
    {
        $parts  = array();
        $start  = 0;
        $state  = CssSyntaxScanner::state();
        $length = strlen($prelude);

        for ( $index = 0; $index < $length; ++$index ) {
            $index = CssSyntaxScanner::inertEnd($prelude, $index, $state, ',');
            if ( $index >= $length ) break;
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
        $tree = $this->tree($css);
        return $this->renderTree($tree, -1, $transformSelectorPrelude, $transformStyleRule, $walkNested, $ancestors);
    }

    /**
     * Cache syntax only, never callback results: projection callbacks record
     * bindings and depend on the current emitted markup and ancestor context.
     *
     * @return list<array{start: int, boundary: int, end: int, prelude: string, body: string, kind: int}>
     */
    private function renderScope(CssRuleTree $tree, int $scope): array
    {
        $scopes = $this->renderScopes[$tree] ?? array();
        if (isset($scopes[$scope])) return $scopes[$scope];
        $records = array();
        foreach ($tree->rules($scope) as $rule) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if (';' === $tree->source[$boundary] || $tree->hasInvalidPrelude($start)) continue;
            $prelude = substr($tree->source, $start, $boundary - $start);
            $body = substr($tree->source, $boundary + 1, $end - $boundary - 1);
            $kind = $this->isAtRule($prelude) ? ($this->walksNestedRules($prelude) ? 2 : 0) : ($this->isStylePrelude($prelude) ? 1 : 0);
            $records[] = array_merge($rule, array('prelude' => $prelude, 'body' => $body, 'kind' => $kind));
        }
        $scopes[$scope] = $records;
        $this->renderScopes[$tree] = $scopes;
        return $records;
    }

    private function renderTree(CssRuleTree $tree, int $scope, callable $selector, ?callable $style, bool $walkNested, array $ancestors): string
    {
        $css = $tree->source;
        $offset = $scope + 1;
        $limit = -1 === $scope ? strlen($css) : $tree->blockEnd($scope);
        $output = '';
        foreach ($this->renderScope($tree, $scope) as $rule) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end, 'prelude' => $prelude, 'body' => $body, 'kind' => $kind] = $rule;
            $replacement = null;
            if (2 === $kind) {
                if ($walkNested) {
                    $nested = $ancestors;
                    $nested[] = trim($prelude);
                    $changed = $this->renderTree($tree, $boundary, $selector, $style, true, $nested);
                    if ($changed !== $body) $replacement = $prelude . '{' . $changed . '}';
                }
            } elseif (1 === $kind) {
                if (null !== $style) $replacement = $style($prelude, $body, $ancestors);
                else {
                    $changed = $selector($prelude, $body, $ancestors);
                    if (is_array($changed)) {
                        $replacement = '';
                        foreach ($changed as $part) $replacement .= $part['prelude'] . '{' . $part['body'] . '}';
                    } elseif ($changed !== $prelude) $replacement = $changed . '{' . $body . '}';
                }
            }
            if (null !== $replacement) {
                $output .= substr($css, $offset, $start - $offset) . $replacement;
                $offset = $end + 1;
            }
        }
        return $output . substr($css, $offset, $limit - $offset);
    }

    /** @param callable(string, string, list<string>): void $visitStyleRule @param callable(string, string): void|null $visitKeyframes @param list<string> $ancestors */
    private function visitRules(string $css, ?callable $visitStyleRule, ?callable $visitKeyframes, array $ancestors): void
    {
        $this->visitTree($this->tree($css), -1, $visitStyleRule, $visitKeyframes, $ancestors);
    }

    private function visitTree(CssRuleTree $tree, int $scope, ?callable $visitStyleRule, ?callable $visitKeyframes, array $ancestors): void
    {
        foreach ($tree->rules($scope) as $rule) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if (';' === $tree->source[$boundary] || $tree->hasInvalidPrelude($start)) continue;
            $prelude = CssSyntaxScanner::withoutComments(substr($tree->source, $start, $boundary - $start));
            $body = substr($tree->source, $boundary + 1, $end - $boundary - 1);
            if ($this->isAtRule($prelude)) {
                $name = self::atRuleName($prelude);
                if (null !== $visitKeyframes && ('keyframes' === $name || str_ends_with($name, '-keyframes'))) {
                    $animationName = self::keyframesAnimationName($prelude);
                    if ('' !== $animationName) $visitKeyframes($animationName, $body);
                } elseif ($this->walksNestedRules($prelude)) {
                    $nested = $ancestors;
                    $nested[] = trim($prelude);
                    $this->visitTree($tree, $boundary, $visitStyleRule, $visitKeyframes, $nested);
                }
            } elseif (null !== $visitStyleRule && $this->isStylePrelude($prelude)) $visitStyleRule($prelude, $body, $ancestors);
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
        return $this->tree($css)->nextBoundary($offset);
    }

    private function isAtRule(string $prelude): bool
    {
        return '@' === self::firstSignificantCharacter($prelude);
    }

    private function isStylePrelude(string $prelude): bool
    {
        return '' !== self::firstSignificantCharacter($prelude) && null !== self::splitSelectorList($prelude);
    }

    public function nestsStyleRules(string $prelude): bool
    {
        return $this->isAtRule($prelude) && $this->walksNestedRules($prelude);
    }

    private function walksNestedRules(string $prelude): bool
    {
        return in_array(self::atRuleName($prelude), array( 'container', 'layer', 'media', 'scope', 'starting-style', 'supports' ), true);
    }

    private function isWellFormedStylesheet(string $css): bool
    {
        return $this->tree($css)->wellFormed;
    }

    private static function firstSignificantCharacter(string $value): string
    {
        return substr(ltrim(CssSyntaxScanner::withoutComments($value), " \t\n\r\f"), 0, 1);
    }

    private static function atRuleName(string $prelude): string
    {
        return preg_match('/^\s*@([a-z-]+)/i', CssSyntaxScanner::withoutComments($prelude), $match) ? strtolower($match[1]) : '';
    }
}

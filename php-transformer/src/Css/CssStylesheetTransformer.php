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
     * When preserving cascade for a later-loaded page projection, also keep
     * every later shared rule that may set a property an already kept rule
     * sets: the page stylesheet loads after the shared one, so a kept rule
     * would otherwise outrank the later shared rule it originally lost to.
     * Kept shared rules count too, since re-emitting one moves it later.
     *
     * @param list<string> $stylesheets
     * @param list<string> $present
     */
    public function rulesAbsentFrom(array $stylesheets, array $present, bool $preserveCascade = false): string
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
        $keptProperties = array();
        foreach ( $stylesheets as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            foreach ( $units as $unit ) {
                $key = self::ruleUnitKey($unit);
                $properties = $preserveCascade ? self::cascadePropertyFamilies($unit['text']) : array();
                if ( ! $unit['dedupable'] || ! isset($seen[ $key ]) || self::cascadeFamiliesOverlap($properties, $keptProperties) ) {
                    $kept[] = $unit;
                    $keptProperties += $properties;
                }
                $seen[ $key ] = true;
            }
        }
        return $this->serializeRuleUnits($kept);
    }

    /**
     * Fold page-only positive identity-marker arms into an unambiguous shared
     * rule, at its original cascade position. A carrier on any consuming page
     * must have projected the very same arm on that rule. Missing page evidence
     * is deliberately unsafe. Return equivalent page rules using the union so
     * rulesAbsentFrom() can remove them without moving a cascade suffix.
     *
     * @param list<string> $shared
     * @param array<string, list<string>> $pages All consuming page projections.
     * @param array<string, string> $markup Canonical markup by page owner.
     * @return array{shared: list<string>, pages: array<string, list<string>>}
     */
    public function foldPositiveMarkerArms(array $shared, array $pages, array $markup): array
    {
        $unchanged = array('shared' => $shared, 'pages' => $pages);
        foreach (array_merge($shared, ...array_values($pages)) as $css) {
            if (!$this->isWellFormedStylesheet($css)) return $unchanged;
        }
        $carriers = array();
        foreach ($markup as $owner => $html) {
            preg_match_all('/(?<![a-zA-Z0-9_-])blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+(?![a-zA-Z0-9_-])/', $html, $matches);
            foreach (array_unique($matches[0]) as $marker) $carriers[$marker][$owner] = true;
        }
        // Only accept a literal marker in a positive :where() compound. This
        // excludes marker negations, constant role classes, escapes and selector-list
        // alternatives that could match without carrying the identity marker.
        $markerOf = static function (string $arm): ?string {
            $positive = preg_replace('/:not\((?:\.be-c-[0-9]+|be-t-[0-9]+)\)/', '', $arm);
            if (str_contains($arm, '/*') || preg_match('/:(?:not|is|has)\s*\(/i', $positive)) return null;
            return preg_match('/^:where\(\.(blocks-engine-[a-z-]+-[a-f0-9]{12}-[0-9]+)\)(?=$|[\s>+~.:#\[])/', $arm, $match) ? $match[1] : null;
        };
        $describe = static function (array $unit) use ($markerOf): ?array {
            $brace = strpos($unit['text'], '{');
            if (!$unit['dedupable'] || false === $brace) return null;
            $prelude = trim(substr($unit['text'], 0, $brace));
            if (str_starts_with($prelude, '@')) return null;
            $arms = self::splitSelectorList($prelude);
            if (null === $arms) return null;
            $arms = array_map('trim', $arms);
            $constant = array_values(array_filter($arms, static fn (string $arm): bool => null === $markerOf($arm)));
            sort($constant);
            return array('key' => serialize(array($unit['context'], substr($unit['text'], $brace), $constant)), 'arms' => $arms, 'brace' => $brace);
        };
        $sharedUnits = array();
        foreach ($shared as $css) $this->collectRuleUnits($css, array(), $sharedUnits);
        $index = array();
        foreach ($sharedUnits as $i => $unit) {
            $description = $describe($unit);
            if (null !== $description) $index[$description['key']][] = $i;
        }
        $pageUnits = array();
        $pageIndex = array();
        foreach ($pages as $owner => $sheets) {
            $pageUnits[$owner] = array();
            foreach ($sheets as $css) $this->collectRuleUnits($css, array(), $pageUnits[$owner]);
            foreach ($pageUnits[$owner] as $i => $unit) {
                $description = $describe($unit);
                if (null !== $description) $pageIndex[$owner][$description['key']][] = $i;
            }
        }
        foreach ($index as $key => $matches) {
            if (1 !== count(array_unique(array_map(static fn (int $i): string => $sharedUnits[$i]['text'], $matches)))) continue;
            $i = $matches[0];
            $base = $describe($sharedUnits[$i]);
            $union = $base['arms'];
            $candidates = array();
            foreach ($pageIndex as $owner => $rules) {
                $pageMatches = $rules[$key] ?? array();
                if (!$pageMatches || 1 !== count(array_unique(array_map(static fn (int $j): string => $pageUnits[$owner][$j]['text'], $pageMatches)))) continue;
                $description = $describe($pageUnits[$owner][$pageMatches[0]]);
                foreach (array_diff($description['arms'], $union) as $arm) {
                    $marker = $markerOf($arm);
                    if (null !== $marker) $candidates[$arm] = $marker;
                }
            }
            foreach ($candidates as $arm => $marker) {
                $safe = true;
                if (array_diff_key($pages, $markup)) $safe = false;
                foreach ($carriers[$marker] ?? array() as $owner => $_) {
                    $rules = $pageIndex[$owner][$key] ?? array();
                    if (!$rules) $safe = false;
                    foreach ($rules as $j) if (!in_array($arm, $describe($pageUnits[$owner][$j])['arms'], true)) $safe = false;
                    if (!$safe) break;
                }
                if ($safe) $union[] = $arm;
            }
            if ($union === $base['arms']) continue;
            foreach ($matches as $match) $sharedUnits[$match]['text'] = implode(',', $union) . substr($sharedUnits[$match]['text'], $base['brace']);
            foreach ($pageIndex as $owner => $rules) {
                foreach ($rules[$key] ?? array() as $j) {
                    $description = $describe($pageUnits[$owner][$j]);
                    if (array() === array_diff($description['arms'], $union)) {
                        $pageUnits[$owner][$j]['text'] = $sharedUnits[$i]['text'];
                    }
                }
            }
        }
        $result = array('shared' => array($this->serializeRuleUnits($sharedUnits)), 'pages' => array());
        foreach ($pageUnits as $owner => $units) $result['pages'][$owner] = array($this->serializeRuleUnits($units));
        return $result;
    }

    /**
     * Reconcile ordered page projections at the source cascade position.
     * Unsupported pages are returned separately for the legacy copy path.
     *
     * Definition at-rules (`@keyframes`, `@font-face`, …) are inert unless a
     * declaration references them, so the ones only some routes keep or add
     * stay site-wide, once each. Two routes that define the same name with
     * different bodies would see each other's definition, so they fall back.
     *
     * @param list<string> $shared
     * @param array<string,list<string>> $pages
     * @param array<string,string> $routeIdentities Route identities by compilation owner.
     * @return array{stylesheet:string, scoped:list<string>, reasons:array<string,string>}
     */
    public function scopeRouteVariants(array $shared, array $pages, array $routeIdentities = array(), ?string $unsafeReason = null): array
    {
        $base = array();
        foreach ($shared as $css) $this->collectRuleUnits($css, array(), $base);
        $normal = static fn (array $unit): string => trim(CssSyntaxScanner::withoutComments($unit['text']));
        $key = function (array $unit) use ($normal): string {
            $rule = self::splitLeadingComments($unit['text'])[1];
            $brace = $this->nextRuleBoundary($rule, 0);
            $selector = null !== $brace && '{' === $rule[$brace] && !$this->isAtRule($rule);
            return serialize(array($unit['context'], $selector ? substr($rule, $brace) : $normal($unit)));
        };
        $value = static fn (array $unit): string => serialize(array($unit['context'], $normal($unit)));
        $positions = $baseDefinitions = $defined = $baseValues = array();
        foreach ($base as $i => $unit) {
            $positions[$key($unit)][] = $i;
            $baseDefinitions[$i] = $this->routeIndependentDefinition($unit);
            if (null === $baseDefinitions[$i]) continue;
            $defined[$baseDefinitions[$i]][$value($unit)] = true;
            $baseValues[$value($unit)] = true;
        }
        $changes = $insertions = $siteInsertions = $reasons = array();
        $scoped = array();
        foreach ($pages as $owner => $sheets) {
            $units = array();
            $reason = $unsafeReason;
            foreach (array_merge($shared, $sheets) as $css) {
                if (!$this->isWellFormedStylesheet($css)) $reason = 'malformed stylesheet';
                if (preg_match('/@namespace\b/i', $css)) $reason = '@namespace';
            }
            if (null !== $reason) { $reasons[$owner] = $reason; continue; }
            foreach ($sheets as $css) $this->collectRuleUnits($css, array(), $units);
            $cursor = 0;
            $localChanges = $localInsertions = $localSite = array();
            foreach ($units as $unit) {
                $match = null;
                foreach ($positions[$key($unit)] ?? array() as $position) {
                    if ($position >= $cursor) { $match = $position; break; }
                }
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
                    $this->scopeRouteUnit($unit, array('be-route-probe'), true, $reason);
                }
            }
            foreach ($localInsertions as $list) foreach ($list as $unit) {
                $this->scopeRouteUnit($unit, array('be-route-probe'), true, $reason);
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
        $routeClass = static fn ($owner): string => \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($routeIdentities[$owner] ?? (string) $owner);
        $emitGroups = function (array $groups) use (&$output, $routeClass): void {
            $texts = array();
            foreach ($groups as $owner => $units) foreach ($units as $ordinal => $unit) {
                $groupKey = serialize(array($ordinal, $unit));
                $texts[$groupKey]['unit'] = $unit;
                $texts[$groupKey]['routes'][] = $routeClass($owner);
            }
            foreach ($texts as $group) $output[] = $this->scopeRouteUnit($group['unit'], $group['routes'], true);
        };
        for ($i = 0; $i <= count($base); ++$i) {
            foreach ($siteInsertions[$i] ?? array() as $unit) $output[] = $unit;
            $emitGroups($insertions[$i] ?? array());
            if ($i === count($base)) break;
            $variants = $changes[$i] ?? array();
            $output[] = $variants ? $this->scopeRouteUnit($base[$i], array_map($routeClass, array_keys($variants)), false) : $base[$i];
            $emitGroups(array_map(static fn ($unit): array => null === $unit ? array() : array($unit), $variants));
        }
        return array('stylesheet' => $this->serializeRuleUnits($output), 'scoped' => $scoped, 'reasons' => $reasons);
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

    /** @param array{context:list<string>,text:string,dedupable:bool} $unit @param list<string> $routes */
    private function scopeRouteUnit(array $unit, array $routes, bool $positive, ?string &$reason = null): array
    {
        [$lead, $rule] = self::splitLeadingComments($unit['text']);
        $brace = $this->nextRuleBoundary($rule, 0);
        if (null === $brace || '{' !== $rule[$brace] || $this->isAtRule($rule) || !$unit['dedupable']) { $reason = 'non-selector rule cannot be route scoped'; return $unit; }
        $arms = self::splitSelectorList(trim(CssSyntaxScanner::withoutComments(substr($rule, 0, $brace))));
        if (null === $arms) { $reason = 'unparseable selector list'; return $unit; }
        $scope = ':where(:root:' . ($positive ? 'is' : 'not') . '(' . implode(',', array_map(static fn (string $route): string => '.' . $route, $routes)) . '))';
        $arms = self::scopeRouteArms($arms, $scope, $reason);
        if (null === $arms) return $unit;
        $unit['text'] = $lead . implode(',', $arms) . substr($rule, $brace);
        return $unit;
    }

    /**
     * Restrict each comment-free selector arm to documents whose root matches
     * $scope, a zero-specificity `:where(:root…)`. The scope prefixes the arm
     * as an ancestor; arms whose first compound can be the root also keep a
     * copy with the scope merged into that compound. A first compound that
     * tests the root inside `:is()`/`:where()` is scoped inside that argument.
     *
     * @param list<string> $arms
     * @return list<string>|null
     */
    private static function scopeRouteArms(array $arms, string $scope, ?string &$reason): ?array
    {
        $rootAlternatives = array();
        foreach ($arms as &$arm) {
            $arm = trim($arm);
            $tokens = CssSelectorTokenizer::tokenize($arm);
            $structural = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\[[^\]]*\]/s', '', $arm) ?? $arm;
            if (!$tokens['supported'] || str_contains($structural, '&') || preg_match('/(?<!\|)\|(?!\|)/', $structural)) { $reason = 'unparseable or relative selector'; return null; }
            $first = $tokens['compounds'][0];
            $end = $tokens['compound_spans'][0]['end'];
            // A pseudo-element ends its compound: merge the scope before it.
            $insert = $end;
            if (1 === count($tokens['compounds'])) {
                $pseudoElement = self::pseudoElementOffset($first);
                if (null !== $pseudoElement) $insert = $pseudoElement;
            }
            if (preg_match('/^(?:html(?=[.#:\[]|$)|:root(?=[.#:\[]|$))/i', $first)) {
                $arm = substr($arm, 0, $insert) . $scope . substr($arm, $insert);
                continue;
            }
            $functional = preg_match('/(?<![\w-])(?:html|:root)\b/i', $first) ? self::scopeFunctionalRoot($first, $scope, $reason) : null;
            if (null !== $reason) return null;
            if (null !== $functional) {
                $arm = $functional . substr($arm, $end);
                continue;
            }
            // Class/universal/attribute/pseudo arms may themselves match html.
            // Retain that match as well as descendants without adding weight.
            if (preg_match('/^[*.#\[:]/', $first)) {
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
    private static function scopeFunctionalRoot(string $compound, string $scope, ?string &$reason): ?string
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
            $scoped = self::scopeRouteArms($alternatives, $scope, $reason);
            if (null === $scoped) return null;
            return substr($compound, 0, $start) . implode(',', $scoped) . substr($compound, $close);
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
     * A rule whose declarations cannot be read (or that sets `all`) reports
     * the wildcard family, which overlaps every other.
     *
     * @return array<string, true>
     */
    private static function cascadePropertyFamilies(string $rule): array
    {
        $brace = strpos($rule, '{');
        if ( false !== $brace && '' === trim(substr(trim($rule), $brace + 1, -1)) ) {
            return array();
        }
        if ( false === $brace || ! str_ends_with(trim($rule), '}') || ! preg_match_all('/(?:^|[;{}])\\s*(-{0,2}[a-zA-Z_][a-zA-Z0-9_-]*)\\s*:/', substr($rule, $brace + 1), $matches) ) {
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
        $parts  = array();
        $start  = 0;
        $state  = CssSyntaxScanner::state();
        $length = strlen($prelude);

        for ( $index = 0; $index < $length; ++$index ) {
            $index += strcspn($prelude, "\\\"'/*()[],", $index);
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
            $prelude = CssSyntaxScanner::withoutComments(substr($css, $offset, $boundary - $offset));
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
            $index += strcspn($css, "\\\"'/*()[]{;", $index);
            if ( $index >= $length ) break;
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
            $index += strcspn($css, "\\\"'/*()[]{}", $index);
            if ( $index >= $length ) break;
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
        $state = CssSyntaxScanner::state();
        $braces = 0;
        $length = strlen($css);
        for ( $offset = 0; $offset < $length; ) {
            // Ordinary bytes cannot change lexical state or brace depth. Scan
            // those runs in C; retain the shared scanner for every delimiter,
            // escape and malformed-input decision (including inside strings).
            $offset += strcspn($css, "\\\"'/*()[]{}", $offset);
            if ( $offset >= $length ) break;
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

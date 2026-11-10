<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Safely visits style-rule selector preludes without parsing or reserializing CSS.
 */
final class CssStylesheetTransformer
{
    private CssRuleTreeCache $localTrees;

    public function __construct()
    {
        $this->localTrees = new CssRuleTreeCache();
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
        $this->visitRules($stylesheet, static function (): void {}, $visitKeyframes, array());
    }

    /**
     * Concatenate stylesheets in order, dropping every rule that recurs later in
     * the result with the same text inside the same conditional group rules.
     *
     * The later copy has the same selectors, specificity and declarations and
     * comes after the earlier one, so the earlier copy can never win the cascade.
     * Named block layers are grouping contexts whose rules dedupe like any
     * other; layer statements and anonymous layers stay whole. The unit that
     * first names a layer is always kept, because it fixes the layer's order.
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

        $stylesheetUnits = array();
        foreach ( $stylesheets as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            $stylesheetUnits[] = $units;
        }
        // A layer's first appearance fixes its order, so the unit that first
        // names a layer stays in place even when a later copy repeats it.
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

        return $this->serializeRuleUnits(array_reverse($kept));
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
        foreach ( $present as $stylesheet ) {
            $units = array();
            $this->collectRuleUnits($stylesheet, array(), $units);
            foreach ( $units as $unit ) {
                $seen[ self::ruleUnitKey($unit) ] = true;
            }
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
     * The page-document elements a rule unit may style, or null when that set
     * cannot be proven — an unreadable selector list or an arm the index
     * cannot evaluate — meaning cascade interaction cannot be disproved.
     *
     * @return array<int|string, true>|null
     */
    private static function cascadeTargets(RouteSelectorMatchIndex $matchIndex, string $owner, string $rule): ?array
    {
        // Keyframes compete only with keyframes of the same name.
        if ( 1 === preg_match('/^@(?:-[a-z]+-)?keyframes\s+([^\s{]+)\s*\{/i', ltrim(CssSyntaxScanner::withoutComments($rule)), $keyframes) ) {
            return array( '@keyframes ' . trim($keyframes[1], '"\'') => true );
        }
        $arms = self::cascadeSelectorArms($rule);
        if ( null === $arms ) {
            return null;
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
            preg_match_all(CssSelectorListCompactor::PAGE_MARKER, $html, $matches);
            foreach (array_unique($matches[0]) as $marker) $carriers[$marker][$owner] = true;
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
     * `route_bytes` counts shipped growth after serialization and recompaction
     * over the unscoped arm-union sheet (including inserted arms).
     *
     * @return array{stylesheet:string, scoped:list<string>, reasons:array<string,string>, route_bytes:int}
     */
    public function scopeRouteVariants(array $shared, array $pages, array $routeIdentities = array(), ?string $unsafeReason = null, array $markup = array(), ?RouteSelectorMatchIndex $matchIndex = null, array $routeClasses = array(), ?string $sharedMarkup = null, ?callable $routeSetClass = null): array
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
        $flush = function () use (&$pending, &$output, &$parsedArms, $routeClass, $routeSetClass, $allRoutes, $baseOwner, $carriers, $markup, $matchIndex): void {
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
                        ? self::scopeRouteArms($arms, $scope, $reason, $parsedArms)
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
     * Drop top-level @import rules that follow other rules. CSS ignores them,
     * so they are inert on every route; concatenated sheets often repeat them.
     *
     * @param list<array{context:list<string>,text:string,dedupable:bool}> $units
     * @return list<array{context:list<string>,text:string,dedupable:bool}>
     */
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

    /**
     * Standalone enumerated scopes and validation probes. Prefix as an ancestor
     * and retain a merged-root alternative for compounds that can match html.
     * @param list<string> $arms
     * @return list<string>|null
     */
    private static function scopeRouteArms(array $arms, string $scope, ?string &$reason, array &$parsedArms): ?array
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
            $functional = preg_match('/(?<![\w-])(?:html|:root)\b/i', $first) ? self::scopeFunctionalRoot($first, $scope, $reason, $parsedArms) : null;
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
    private static function scopeFunctionalRoot(string $compound, string $scope, ?string &$reason, array &$parsedArms): ?string
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
            $scoped = self::scopeRouteArms($alternatives, $scope, $reason, $parsedArms);
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
     * A statement without a block declares nothing. A rule whose declarations
     * cannot be read (or that sets `all`) reports the wildcard family, which
     * overlaps every other.
     *
     * @return array<string, true>
     */
    private static function cascadePropertyFamilies(string $rule): array
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
        return md5(implode("\0", $unit['context']) . "\0" . trim($unit['text']), true);
    }

    /** @param list<array{context: list<string>, text: string, dedupable: bool}> $units */
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

    private function renderTree(CssRuleTree $tree, int $scope, callable $selector, ?callable $style, bool $walkNested, array $ancestors): string
    {
        $css = $tree->source;
        $offset = $scope + 1;
        $limit = -1 === $scope ? strlen($css) : $tree->blockEnd($scope);
        $output = '';
        foreach ($tree->rules($scope) as $rule) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if (';' === $css[$boundary]) continue;
            $prelude = substr($css, $start, $boundary - $start);
            $body = substr($css, $boundary + 1, $end - $boundary - 1);
            $replacement = null;
            if ($this->isAtRule($prelude)) {
                if ($walkNested && $this->walksNestedRules($prelude)) {
                    $nested = $ancestors;
                    $nested[] = trim($prelude);
                    $changed = $this->renderTree($tree, $boundary, $selector, $style, true, $nested);
                    if ($changed !== $body) $replacement = $prelude . '{' . $changed . '}';
                }
            } elseif ($this->isStylePrelude($prelude)) {
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
    private function visitRules(string $css, callable $visitStyleRule, ?callable $visitKeyframes, array $ancestors): void
    {
        $this->visitTree($this->tree($css), -1, $visitStyleRule, $visitKeyframes, $ancestors);
    }

    private function visitTree(CssRuleTree $tree, int $scope, callable $visitStyleRule, ?callable $visitKeyframes, array $ancestors): void
    {
        foreach ($tree->rules($scope) as $rule) {
            ['start' => $start, 'boundary' => $boundary, 'end' => $end] = $rule;
            if (';' === $tree->source[$boundary]) continue;
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
            } elseif ($this->isStylePrelude($prelude)) $visitStyleRule($prelude, $body, $ancestors);
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

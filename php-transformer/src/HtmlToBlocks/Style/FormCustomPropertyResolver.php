<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use DOMElement;

/**
 * Resolves bounded custom-property rules at a form element's cascade scope.
 *
 * An instance lives as long as its selector match cache and assumes the same
 * stable source DOM. Every value a form resolves walks the same ancestors
 * against the same rules, so each ancestor's computed custom properties are
 * kept per rule set and condition and shared by its descendants.
 */
final class FormCustomPropertyResolver
{
    private const MAX_EXPANSION_DEPTH = 5;
    private const DEFAULT_VIEWPORT_PX = 1280;
    private const MAX_REMEMBERED_RULE_SETS = 8;

    /** @var array<string, array<string, array{node: DOMElement, properties: array<string, string>, state: string}>> Scope key, then node path. */
    private array $computedAt = array();

    /** @var array<string, array<string, array{node: DOMElement, properties: array<string, string>}>> Rule set key, then cascade state. */
    private array $computedStates = array();

    /** @var array<string, array<string, array{node: DOMElement, rules: list<int|string>}>> Rule set key, then node path. */
    private array $matchingRules = array();

    /** @var list<array{0: list<array<string, mixed>>, 1: string}> Rule lists already keyed, oldest first. */
    private array $ruleSetKeys = array();

    private static int $computedDeclarations = 0;

    public function __construct(private readonly ?CssSelectorMatchCache $cache = null)
    {
    }

    /**
     * Custom-property declarations computed since process start; observable
     * for tests. Each media condition is its own scope, so this must not grow
     * with conditions times the properties every scope inherits.
     */
    public static function computedDeclarations(): int
    {
        return self::$computedDeclarations;
    }

    /** @param list<array<string, mixed>> $rules */
    public function resolve(string $value, DOMElement $element, ?array $condition, array $rules, bool $unconditionalBase = false): string
    {
        if ( ! str_contains($value, 'var(') ) {
            return trim($value);
        }
        $customProperties = $this->propertiesAt($element, $condition, $rules, $unconditionalBase);
        return CssVariableExpander::expand($value, static fn (string $name): ?string => $customProperties[$name] ?? null) ?? trim($value);
    }

    /** @param list<array<string, mixed>> $rules @return list<array<string, mixed>> */
    public function conditionsChanging(string $value, DOMElement $element, array $rules, bool $unconditionalBase = false): array
    {
        if ( ! str_contains($value, 'var(') ) {
            return array();
        }
        $base = $this->resolve($value, $element, null, $rules, $unconditionalBase);
        // Rules that match the element or one of its ancestors, from the
        // per-node match lists propertiesAt() already keeps.
        $ruleSet = $this->ruleSetKey($rules);
        $matchedRules = array();
        for ( $ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
            foreach ( $this->rulesMatching($ancestor, $rules, $ruleSet) as $index ) {
                $matchedRules[$index] = true;
            }
        }
        $conditions = array();
        $resolved = array();
        foreach ( $rules as $index => $rule ) {
            $condition = $rule['condition'] ?? null;
            if ( null === $condition || ! isset($matchedRules[$index]) ) {
                continue;
            }
            // A condition resolves the same value whichever of its rules
            // brought it here, so each is resolved once.
            $key = json_encode($condition);
            if ( isset($resolved[$key]) ) {
                continue;
            }
            $resolved[$key] = true;
            if ( $this->resolve($value, $element, $condition, $rules) !== $base ) {
                $conditions[$key] = $condition;
            }
        }
        return array_values($conditions);
    }

    /**
     * The computed custom properties at $element: each ancestor's cascaded
     * declarations, computed where declared and inherited downward.
     *
     * Every condition is its own scope, but most conditions change nothing on
     * most ancestors. An ancestor whose applying rules and inherited state
     * equal those of a scope already computed shares that result instead of
     * expanding every declared property again.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, string>
     */
    private function propertiesAt(DOMElement $element, ?array $condition, array $rules, bool $unconditionalBase): array
    {
        $ruleSet = $this->ruleSetKey($rules);
        $scope = $this->scopeKey($condition, $ruleSet, $unconditionalBase);
        $properties = array();
        $state = '';
        $pending = array();
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $path = (string) $current->getNodePath();
            $known = $this->computedAt[$scope][$path] ?? null;
            if ( null !== $known && $known['node']->isSameNode($current) ) {
                $properties = $known['properties'];
                $state = $known['state'];
                break;
            }
            $pending[] = array( $current, $path );
        }
        foreach ( array_reverse($pending) as [$ancestor, $path] ) {
            $applying = array();
            foreach ( $this->rulesMatching($ancestor, $rules, $ruleSet) as $index ) {
                $ruleCondition = $rules[$index]['condition'] ?? null;
                if ( ($unconditionalBase && null === $condition && null !== $ruleCondition) || ! self::ruleConditionApplies($ruleCondition, $condition) ) {
                    continue;
                }
                $applying[] = $index;
            }
            // The inherited state and the applying rules decide the result.
            $state = hash('xxh128', $state . "\0" . $path . "\0" . implode(',', $applying));
            $known = $this->computedStates[$ruleSet][$state] ?? null;
            if ( null !== $known && $known['node']->isSameNode($ancestor) ) {
                $properties = $known['properties'];
                $this->computedAt[$scope][$path] = array( 'node' => $ancestor, 'properties' => $properties, 'state' => $state );
                continue;
            }
            // Cascade each element before applying its declarations over inherited values.
            $declared = array();
            foreach ( $applying as $index ) {
                $rule = $rules[$index];
                foreach ( $rule['declarations'] as $declaration ) {
                    if ( ! str_starts_with($declaration['name'], '--') ) {
                        continue;
                    }
                    $declarationValue = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                    CssCascade::apply($declared, $declaration['name'], array( 'value' => $declarationValue, 'order' => $rule['order'], 'specificity' => $rule['specificity'], 'important' => 1 === preg_match('/\s*!important\s*$/i', $declaration['value']) ));
                }
            }
            if ( $ancestor->hasAttribute('style') ) {
                $inline = $ancestor->getAttribute('style');
                foreach ( CssRuleAnalyzer::declarations($inline, array('--*')) as $declaration ) {
                    $declarationValue = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                    CssCascade::apply($declared, $declaration['name'], array( 'value' => $declarationValue, 'order' => PHP_INT_MAX, 'specificity' => PHP_INT_MAX, 'important' => 1 === preg_match('/\s*!important\s*$/i', $declaration['value']) ));
                }
            }
            // Custom-property values are computed where they are declared. A
            // descendant override must not alter an already inherited value.
            self::$computedDeclarations += count($declared);
            foreach ( self::computed($declared, $properties) as $name => $computedValue ) {
                if ( null === $computedValue ) {
                    unset($properties[$name]);
                    continue;
                }
                $properties[$name] = $computedValue;
            }
            $this->computedStates[$ruleSet][$state] = array( 'node' => $ancestor, 'properties' => $properties );
            $this->computedAt[$scope][$path] = array( 'node' => $ancestor, 'properties' => $properties, 'state' => $state );
        }
        return $properties;
    }

    /**
     * Keys of the rules whose selector matches $element, in rule order,
     * whatever their condition. Matching does not depend on the condition,
     * so every scope reads one list per element.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<int|string>
     */
    private function rulesMatching(DOMElement $element, array $rules, string $ruleSet): array
    {
        $path = (string) $element->getNodePath();
        $known = $this->matchingRules[$ruleSet][$path] ?? null;
        if ( null !== $known && $known['node']->isSameNode($element) ) {
            return $known['rules'];
        }
        $matching = array();
        foreach ( $rules as $index => $rule ) {
            if ( $this->matches($element, $rule) ) {
                $matching[] = $index;
            }
        }
        $this->matchingRules[$ruleSet][$path] = array( 'node' => $element, 'rules' => $matching );
        return $matching;
    }

    private function scopeKey(?array $condition, string $ruleSet, bool $unconditionalBase): string
    {
        return hash('xxh128', serialize(array( $condition, $unconditionalBase, $ruleSet )));
    }

    /**
     * The identity of a rule set. Every value a form resolves passes the same
     * rule list, so the key is remembered for that list instead of
     * serializing every rule again on each call. `===` on the list a caller
     * passes again is a pointer comparison.
     *
     * @param list<array<string, mixed>> $rules
     */
    private function ruleSetKey(array $rules): string
    {
        foreach ( $this->ruleSetKeys as [$known, $key] ) {
            if ( $known === $rules ) {
                return $key;
            }
        }
        $identity = array();
        foreach ( $rules as $rule ) {
            $identity[] = array( $rule['selector'] ?? $rule['parsed_selector'], $rule['condition'] ?? null, $rule['declarations'], $rule['order'], $rule['specificity'] );
        }
        $key = hash('xxh128', serialize($identity));
        if ( count($this->ruleSetKeys) >= self::MAX_REMEMBERED_RULE_SETS ) {
            array_shift($this->ruleSetKeys);
        }
        $this->ruleSetKeys[] = array( $rules, $key );
        return $key;
    }

    /** @param array<string, mixed> $rule */
    private function matches(DOMElement $element, array $rule): bool
    {
        return null !== $this->cache && isset($rule['selector'])
            ? $this->cache->matches($element, (string) $rule['selector'], $rule['parsed_selector'])['matches']
            : CssSelectorMatcher::matches($element, $rule['parsed_selector'])['matches'];
    }

    /** @param array<string, mixed>|null $ruleCondition @param array<string, mixed>|null $requested */
    private static function ruleConditionApplies(?array $ruleCondition, ?array $requested): bool
    {
        if ( null === $ruleCondition ) {
            return true;
        }
        if ( null !== $requested ) {
            return $ruleCondition === $requested;
        }

        return self::mediaMatchesDefaultViewport($ruleCondition);
    }

    /** @param array<string, mixed> $condition */
    private static function mediaMatchesDefaultViewport(array $condition): bool
    {
        if ( 'media' !== ( $condition['kind'] ?? null ) ) {
            return false;
        }
        $query = strtolower(trim((string) ( $condition['query'] ?? '' )));
        if ( 1 === preg_match('/^\(\s*min-width\s*:\s*([0-9]+)px\s*\)$/', $query, $match) ) {
            return self::DEFAULT_VIEWPORT_PX >= (int) $match[1];
        }
        if ( 1 === preg_match('/^\(\s*max-width\s*:\s*([0-9]+)px\s*\)$/', $query, $match) ) {
            return self::DEFAULT_VIEWPORT_PX <= (int) $match[1];
        }

        return false;
    }

    /** @param array<string, array<string, mixed>> $declared @param array<string, string> $inherited @return array<string, string|null> */
    private static function computed(array $declared, array $inherited): array
    {
        $computed = array();
        $resolving = array();
        $invalid = array();
        $resolve = null;
        $resolve = static function (string $name, int $depth = 0) use (&$resolve, &$computed, &$resolving, &$invalid, $declared, $inherited): ?string {
            if ( array_key_exists($name, $computed) ) return $computed[$name];
            if ( ! isset($declared[$name]) ) return $inherited[$name] ?? null;
            if ( $depth >= self::MAX_EXPANSION_DEPTH || isset($resolving[$name]) ) {
                foreach ( array_keys($resolving) as $resolvingName ) $invalid[$resolvingName] = true;
                $invalid[$name] = true;
                return null;
            }
            $resolving[$name] = true;
            $value = CssVariableExpander::expand($declared[$name]['value'], static fn (string $reference): ?string => $resolve($reference, $depth + 1));
            unset($resolving[$name]);
            if ( isset($invalid[$name]) ) $value = null;
            $computed[$name] = $value;
            return $value;
        };
        foreach ( array_keys($declared) as $name ) $resolve($name);
        return $computed;
    }

}

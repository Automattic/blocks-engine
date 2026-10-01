<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use DOMElement;

/** Resolves bounded custom-property rules at a form element's cascade scope. */
final class FormCustomPropertyResolver
{
    private const MAX_EXPANSION_DEPTH = 5;
    private const DEFAULT_VIEWPORT_PX = 1280;

    /** @param list<array<string, mixed>> $rules */
    public static function resolve(string $value, DOMElement $element, ?array $condition, array $rules, bool $unconditionalBase = false): string
    {
        if ( ! str_contains($value, 'var(') ) {
            return trim($value);
        }
        $properties = array();
        $ancestors = array();
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $ancestors[] = $current;
        }
        foreach ( array_reverse($ancestors) as $ancestor ) {
            // Cascade each element before applying its declarations over inherited values.
            $declared = array();
            foreach ( $rules as $rule ) {
                if ( ($unconditionalBase && null === $condition && null !== ($rule['condition'] ?? null)) || ! self::ruleConditionApplies($rule['condition'] ?? null, $condition) || ! CssSelectorMatcher::matches($ancestor, $rule['parsed_selector'])['matches'] ) {
                    continue;
                }
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
            $inherited = array_map(static fn (array $fact): string => $fact['value'], $properties);
            foreach ( self::computed($declared, $inherited) as $name => $computedValue ) {
                if ( null === $computedValue ) {
                    unset($properties[$name]);
                    continue;
                }
                $properties[$name] = $declared[$name] + array( 'value' => $computedValue );
                $properties[$name]['value'] = $computedValue;
            }
        }
        $customProperties = array_map(static fn (array $fact): string => $fact['value'], $properties);
        return CssVariableExpander::expand($value, static fn (string $name): ?string => $customProperties[$name] ?? null) ?? trim($value);
    }

    /** @param list<array<string, mixed>> $rules @return list<array<string, mixed>> */
    public static function conditionsChanging(string $value, DOMElement $element, array $rules, bool $unconditionalBase = false): array
    {
        if ( ! str_contains($value, 'var(') ) {
            return array();
        }
        $base = self::resolve($value, $element, null, $rules, $unconditionalBase);
        $conditions = array();
        foreach ( $rules as $rule ) {
            $condition = $rule['condition'] ?? null;
            if ( null === $condition ) {
                continue;
            }
            $matchesAncestor = false;
            for ( $ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
                if ( CssSelectorMatcher::matches($ancestor, $rule['parsed_selector'])['matches'] ) {
                    $matchesAncestor = true;
                    break;
                }
            }
            if ( ! $matchesAncestor ) continue;
            $key = json_encode($condition);
            if ( ! isset($conditions[$key]) && self::resolve($value, $element, $condition, $rules) !== $base ) {
                $conditions[$key] = $condition;
            }
        }
        return array_values($conditions);
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

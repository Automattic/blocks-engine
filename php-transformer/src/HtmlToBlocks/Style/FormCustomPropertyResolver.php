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
    private const MAX_EXPANDED_BYTES = 4096;

    /** @param list<array<string, mixed>> $rules */
    public static function resolve(string $value, DOMElement $element, ?array $condition, array $rules): string
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
                // A conditional cascade inherits its base custom properties while excluding other conditions.
                if ( ( null !== ($rule['condition'] ?? null) && ($rule['condition'] ?? null) !== $condition ) || ! CssSelectorMatcher::matches($ancestor, $rule['parsed_selector'])['matches'] ) {
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
        return self::expandWith($value, static fn (string $name): ?string => $customProperties[$name] ?? null) ?? trim($value);
    }

    /** @param list<array<string, mixed>> $rules @return list<array<string, mixed>> */
    public static function conditionsChanging(string $value, DOMElement $element, array $rules): array
    {
        if ( ! str_contains($value, 'var(') ) {
            return array();
        }
        $base = self::resolve($value, $element, null, $rules);
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
            $value = self::expandWith($declared[$name]['value'], static fn (string $reference): ?string => $resolve($reference, $depth + 1));
            unset($resolving[$name]);
            if ( isset($invalid[$name]) ) $value = null;
            $computed[$name] = $value;
            return $value;
        };
        foreach ( array_keys($declared) as $name ) $resolve($name);
        return $computed;
    }

    /** @param callable(string): ?string $resolve */
    private static function expandWith(string $value, callable $resolve): ?string
    {
        $seen = array();
        for ( $pass = 0; $pass < self::MAX_EXPANSION_DEPTH && str_contains($value, 'var('); ++$pass ) {
            if ( strlen($value) > self::MAX_EXPANDED_BYTES ) return null;
            if ( isset($seen[$value]) ) return null;
            $seen[$value] = true;
            $unresolved = false;
            $expanded = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/', static function (array $matches) use ($resolve, &$unresolved): string {
                $resolved = $resolve($matches[1]);
                if ( null !== $resolved ) return $resolved;
                if ( isset($matches[2]) ) return trim($matches[2]);
                $unresolved = true;
                return $matches[0];
            }, $value);
            if ( ! is_string($expanded) || $unresolved ) return null;
            if ( $expanded === $value ) break;
            $value = $expanded;
        }
        return str_contains($value, 'var(') || strlen($value) > self::MAX_EXPANDED_BYTES ? null : trim($value);
    }
}

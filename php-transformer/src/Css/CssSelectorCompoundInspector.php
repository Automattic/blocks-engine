<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared inspection helpers for selectors and their parsed compounds. */
final class CssSelectorCompoundInspector
{
    /**
     * The subject (rightmost) compound, or an empty compound when there is none.
     *
     * @param array<int, array<string, mixed>> $compounds
     * @return array<string, mixed>
     */
    public static function rightmost(array $compounds): array
    {
        return array() === $compounds ? array() : $compounds[array_key_last($compounds)];
    }

    /**
     * Whether a source-image subject compound is a bare `img`/`svg`/universal
     * type. Such rules keep sizing the replaced image leaf; any class, id or
     * attribute identity moves to the figure core/image synthesizes around it.
     *
     * @param array<string, mixed> $compound
     */
    public static function isBareImageSubject(array $compound): bool
    {
        return in_array(strtolower((string) ($compound['type'] ?? '*')), array( 'img', 'svg', '*' ), true)
            && array() === ($compound['classes'] ?? array())
            && array() === ($compound['ids'] ?? array())
            && array() === ($compound['attributes'] ?? array());
    }

    /** @param array<string, mixed> $compound @return list<string> */
    public static function dataAttributeNames(array $compound): array
    {
        $names = array();
        foreach ($compound['attributes'] ?? array() as $attribute) {
            $name = (string) ($attribute['name'] ?? '');
            if (str_starts_with($name, 'data-')) $names[] = $name;
        }
        foreach ($compound['not'] ?? array() as $negated) {
            foreach ($negated['compounds'] ?? array() as $nested) {
                array_push($names, ...self::dataAttributeNames($nested));
            }
        }
        foreach ($compound['any'] ?? array() as $group) {
            foreach ($group['alternatives'] ?? array() as $alternative) {
                foreach ($alternative['compounds'] ?? array() as $nested) {
                    array_push($names, ...self::dataAttributeNames($nested));
                }
            }
        }
        foreach ($compound['has'] ?? array() as $group) {
            foreach ($group['alternatives'] ?? array() as $alternative) {
                foreach ($alternative['compounds'] ?? array() as $nested) {
                    array_push($names, ...self::dataAttributeNames($nested));
                }
            }
        }
        return array_values(array_unique($names));
    }

    /** @param array<string, mixed> $compound */
    public static function containsDataAttribute(array $compound): bool
    {
        foreach ( $compound['attributes'] ?? array() as $attribute ) {
            if ( str_starts_with((string) ($attribute['name'] ?? ''), 'data-') ) {
                return true;
            }
        }
        foreach ( $compound['not'] ?? array() as $negated ) {
            foreach ( $negated['compounds'] ?? array() as $nested ) {
                if ( self::containsDataAttribute($nested) ) {
                    return true;
                }
            }
        }
        foreach ( $compound['any'] ?? array() as $group ) {
            foreach ( $group['alternatives'] ?? array() as $alternative ) {
                foreach ( $alternative['compounds'] ?? array() as $nested ) {
                    if ( self::containsDataAttribute($nested) ) {
                        return true;
                    }
                }
            }
        }
        foreach ( $compound['has'] ?? array() as $group ) {
            foreach ( $group['alternatives'] ?? array() as $alternative ) {
                foreach ( $alternative['compounds'] ?? array() as $nested ) {
                    if ( self::containsDataAttribute($nested) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $compound */
    public static function hasLiveAttributePredicate(array $compound): bool
    {
        foreach ( $compound['attributes'] ?? array() as $attribute ) {
            if ( ! str_starts_with((string) ($attribute['name'] ?? ''), 'data-') ) {
                return true;
            }
        }
        foreach ( $compound['not'] ?? array() as $negated ) {
            foreach ( $negated['compounds'] ?? array() as $nested ) {
                if ( self::hasLiveAttributePredicate($nested) ) {
                    return true;
                }
            }
        }
        foreach ( $compound['has'] ?? array() as $group ) {
            foreach ( $group['alternatives'] ?? array() as $alternative ) {
                foreach ( $alternative['compounds'] ?? array() as $nested ) {
                    if ( array() !== ($nested['attributes'] ?? array()) || self::hasLiveAttributePredicate($nested) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Live class conditions left after binding a source compound to a marker.
     * Complex/structural predicates cannot safely move to a generated carrier.
     *
     * @param array<string, mixed> $compound
     */
    public static function markerClassConditions(array $compound, bool $classOnly = false): ?string
    {
        if (!empty($compound['has']) || !empty($compound['resting_state_negations'])
            || !empty($compound['root']) || !empty($compound['first_child']) || !empty($compound['last_child'])
            || !empty($compound['nth_child']) || !empty($compound['nth_type']) || !empty($compound['last_type'])
            || ($classOnly && (!empty($compound['type']) || self::hasLiveAttributePredicate($compound) || !empty($compound['empty'])))) {
            return null;
        }
        $conditions = CssIdent::compoundClassSelector($compound['classes'] ?? array());
        foreach ($compound['any'] ?? array() as $group) {
            $arms = array();
            foreach ($group['alternatives'] as $alternative) {
                if (1 !== count($alternative['compounds']) || !empty($alternative['combinators'])) return null;
                $arm = self::markerClassConditions($alternative['compounds'][0], true);
                if (null === $arm) return null;
                $arms[] = $arm;
            }
            if (array('') === array_values(array_unique($arms))) continue;
            if (in_array('', $arms, true)) return null;
            $conditions .= (0 === ($group['specificity'] ?? 0) ? ':where(' : ':is(') . implode(',', $arms) . ')';
        }
        foreach ($compound['not'] ?? array() as $negated) {
            if (1 !== count($negated['compounds']) || !empty($negated['combinators'])) return null;
            $arm = self::markerClassConditions($negated['compounds'][0], true);
            if (null === $arm) return null;
            if ('' !== $arm) $conditions .= ':not(' . $arm . ')';
        }
        if ($classOnly && (self::containsDataAttribute($compound) || !empty($compound['ids'])) && '' !== $conditions) return null;
        return $conditions;
    }

    /** The selector with every `:not(...)` removed: what it targets, not what it excludes. */
    public static function positiveSelector(string $selector): string
    {
        $positive = '';
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            if (0 === $depth && 0 === strncasecmp(substr($selector, $i, 5), ':not(', 5)) {
                $depth = 1;
                $i += 4;
                continue;
            }
            if ($depth > 0) {
                if ('(' === $selector[$i]) ++$depth;
                elseif (')' === $selector[$i]) --$depth;
                continue;
            }
            $positive .= $selector[$i];
        }
        return $positive;
    }
}

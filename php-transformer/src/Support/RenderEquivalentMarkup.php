<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * One spelling for markup that renders identically but serializes differently.
 *
 * The same component captured from two pages can differ only in how it was
 * written down: a server-rendered string versus a browser-reserialized DOM
 * (`style="display:grid" class="x"` versus `class="x" style="display: grid;"`),
 * a zero box length spelled `0px`, `0%` or `calc(0px)`, or a capture diagnostic
 * attribute (`data-dla-anchor-unresolved`) recording how one page was observed.
 * Anything comparing such components for identity reads them through this
 * canonical form; emitted markup is never rewritten by it.
 */
final class RenderEquivalentMarkup
{
    /** Capture diagnostics that describe how a page was observed, not what it renders. */
    private const DIAGNOSTIC_ATTRIBUTES = array('data-dla-anchor-unresolved');

    /**
     * Whether a single CSS value is a zero length or percentage: `0`, `0px`,
     * `0%`, `-0em`, and a `calc()` that wraps only such a zero (`calc(0px)`).
     */
    public static function isZeroLength(string $value): bool
    {
        $value = strtolower(trim($value));
        while ( preg_match('/^calc\(\s*(.*?)\s*\)$/', $value, $inner) ) {
            $value = $inner[1];
        }
        return 1 === preg_match('/^[+-]?0*(?:\.0+)?(?:[a-z]+|%)?$/', $value) && 1 === preg_match('/0/', $value);
    }

    /** One spelling, `0px`, for a zero box length; any other value is returned unchanged. */
    public static function canonicalZeroLength(string $value): string
    {
        return self::isZeroLength($value) ? '0px' : $value;
    }

    public static function canonical(string $html): string
    {
        return preg_replace_callback('/<([a-zA-Z][a-zA-Z0-9-]*)(\s[^<>]*?)?(\/?)>/', static function (array $match): string {
            $attributes = trim($match[2] ?? '');
            if ('' === $attributes) return $match[0];
            if (!preg_match_all('/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $attributes, $pairs, PREG_SET_ORDER)) return $match[0];
            $parsed = array();
            foreach ($pairs as $pair) {
                $name = strtolower($pair[1]);
                if (in_array($name, self::DIAGNOSTIC_ATTRIBUTES, true)) continue;
                $value = $pair[2] ?? '';
                if ('' === $value && isset($pair[3]) && '' !== $pair[3]) $value = $pair[3];
                if ('' === $value && isset($pair[4]) && '' !== $pair[4]) $value = $pair[4];
                $hasValue = isset($pair[2]) || isset($pair[3]) || isset($pair[4]);
                if ('style' === $name) $value = self::canonicalStyle($value);
                if ('class' === $name) {
                    $classes = preg_split('/\s+/', trim($value)) ?: array();
                    sort($classes, SORT_STRING);
                    $value = implode(' ', $classes);
                }
                $parsed[$name] = $hasValue ? $name . '="' . $value . '"' : $name;
            }
            ksort($parsed, SORT_STRING);
            return '<' . $match[1] . (array() === $parsed ? '' : ' ' . implode(' ', $parsed)) . $match[3] . '>';
        }, $html) ?? $html;
    }

    /** Inline declarations as `property:value;…`, trimmed, with zero box lengths spelled `0px`. */
    public static function canonicalStyle(string $style): string
    {
        /** @var array<string,string> $declarations */
        $declarations = array();
        foreach (self::splitDeclarations($style) as $declaration) {
            $colon = strpos($declaration, ':');
            if (false === $colon) continue;
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $value = trim(substr($declaration, $colon + 1));
            if ('' === $property || '' === $value) continue;
            if (preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left))?$/', $property)) {
                $value = implode(' ', array_map(static fn (string $token): string => self::canonicalZeroLength($token), self::splitTokens($value)));
            }
            // A browser reserializes `grid-row` and `grid-column` as the
            // `grid-area` shorthand; read both spellings as the longhands.
            if ('grid-area' === $property && 4 === count($lines = array_map('trim', explode('/', $value)))) {
                $declarations['grid-row'] = $lines[0] . ' / ' . $lines[2];
                $declarations['grid-column'] = $lines[1] . ' / ' . $lines[3];
                continue;
            }
            $declarations[$property] = preg_replace('/\s+/', ' ', $value);
        }
        foreach ($declarations as $property => $value) {
            if (in_array($property, array('grid-row', 'grid-column'), true)) $declarations[$property] = preg_replace('/\s*\/\s*/', ' / ', $value);
        }
        // Distinct properties that no shorthand in the list overlaps apply in any
        // order; only then is their written order not part of the rendering.
        $names = array_keys($declarations);
        $overlap = false;
        foreach ($names as $name) foreach ($names as $other) if ($name !== $other && str_starts_with($other, $name . '-')) $overlap = true;
        if (!$overlap) ksort($declarations, SORT_STRING);
        return implode(';', array_map(static fn (string $property, string $value): string => $property . ':' . $value, array_keys($declarations), $declarations));
    }

    /** @return list<string> */
    private static function splitDeclarations(string $style): array
    {
        return self::splitOutsideParentheses($style, ';');
    }

    /** @return list<string> */
    private static function splitTokens(string $value): array
    {
        return array_values(array_filter(self::splitOutsideParentheses(trim($value), ' '), static fn (string $token): bool => '' !== trim($token)));
    }

    /** @return list<string> */
    private static function splitOutsideParentheses(string $value, string $separator): array
    {
        $parts = array();
        $current = '';
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ('(' === $char) ++$depth;
            elseif (')' === $char) $depth = max(0, $depth - 1);
            if (0 === $depth && ($char === $separator || (' ' === $separator && ctype_space($char)))) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;
        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => '' !== $part));
    }
}

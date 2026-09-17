<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;

/** Shared author-origin importance, specificity, and source-order cascade. */
final class CssCascade
{
    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    public static function wins(array $candidate, array $current): bool
    {
        if ((bool) $candidate['important'] !== (bool) $current['important']) {
            return (bool) $candidate['important'];
        }
        if ((bool) ($candidate['inline'] ?? false) !== (bool) ($current['inline'] ?? false)) {
            return (bool) ($candidate['inline'] ?? false);
        }
        $layer = self::compareLayers($candidate['layer'] ?? null, $current['layer'] ?? null, (bool) $candidate['important']);
        if (0 !== $layer) {
            return 0 < $layer;
        }
        $specificity = self::compareSpecificity($candidate['specificity'], $current['specificity']);
        return 0 < $specificity || (0 === $specificity && $candidate['order'] >= $current['order']);
    }

    /** @param array<string, array<string, mixed>> $facts @param array<string, mixed> $candidate */
    public static function apply(array &$facts, string $property, array $candidate): bool
    {
        $current = $facts[$property] ?? null;
        if ( ! is_array($current) || self::wins($candidate, $current) ) {
            $facts[$property] = $candidate;
            return true;
        }
        return false;
    }

    /** Evaluate a comma-separated visual media query list at a viewport width. */
    public static function mediaConditionApplies(string $condition, float $viewportWidth, float $rootFontSize = 16.0): bool
    {
        foreach (\Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevel($condition, array(',')) as $alternative) {
            if (self::mediaAlternativeApplies($alternative, $viewportWidth, $rootFontSize)) {
                return true;
            }
        }
        return false;
    }

    /**
     * CSS colour functions that every evergreen browser has shipped.
     *
     * All six reached Baseline widely available together — interoperable since
     * May 2023 (Chrome 111, Safari 15.4/16.2, Firefox 113), the Baseline 2023
     * colour cohort. A browser running the transformed output resolves an
     * `@supports` block gating one of these to true, so the engine has to as
     * well.
     *
     * Relative colour syntax (`lch(from red l c h)`) is deliberately absent. It
     * is a later, still-diverging feature — MDN's own guide gates it behind
     * `@supports (color: lch(from red l c calc(h + 180deg)))` to tell Safari's
     * first implementation apart from the current one — so its truth value here
     * stays unknown.
     */
    private const WIDELY_AVAILABLE_COLOR_FUNCTIONS = array( 'color', 'color-mix', 'lab', 'lch', 'oklab', 'oklch' );

    /**
     * Evaluate the supported subset of a CSS @supports condition.
     *
     * The tri-state evaluator preserves unknown terms through `not`, `and`, and
     * `or`; callers only receive true when the condition is positively known.
     */
    public static function supportsConditionApplies(string $condition): bool
    {
        return true === self::booleanConditionValue($condition, static fn (string $term): ?bool => self::supportsTermValue($term));
    }

    /** Whether a single `property: value` support test is known true, or null when unknown. */
    private static function supportsTermValue(string $term): ?bool
    {
        [$property, $value] = array_pad(array_map('trim', explode(':', strtolower($term), 2)), 2, '');
        return ('display' === $property && in_array($value, array( 'flex', 'grid', 'inline-flex', 'inline-grid' ), true))
            || ('aspect-ratio' === $property && 1 === preg_match('#^\d*\.?\d+\s*(?:/\s*\d*\.?\d+)?$#', $value))
            || ('object-fit' === $property && in_array($value, array( 'cover', 'contain' ), true))
            || self::declaresWidelyAvailableColorFunction($property, $value)
            ? true
            : null;
    }

    /**
     * Whether a support test paints a colour property with a widely available
     * colour function.
     *
     * Tailwind v4 emits every opacity-modified colour as a progressive
     * enhancement pair — an opaque fallback, then the translucent value behind
     * `@supports (color: color-mix(in lab, red, red))`. Reading that gate as
     * unknown takes the fallback and renders the whole page opaque.
     *
     * The property has to accept a colour for the test to be true: every CSS
     * colour property is named `color` or `<something>-color`, plus the SVG
     * paints. A colour function under any other property stays unknown rather
     * than becoming true.
     */
    private static function declaresWidelyAvailableColorFunction(string $property, string $value): bool
    {
        if ( 1 !== preg_match('/(?:^|-)color$/', $property) && ! in_array($property, array( 'fill', 'stroke' ), true) ) {
            return false;
        }
        $name = self::soleFunctionName($value);
        if ( ! in_array($name, self::WIDELY_AVAILABLE_COLOR_FUNCTIONS, true) ) {
            return false;
        }
        // `color(from red srgb r g b)` is relative colour syntax wearing an
        // allowlisted function's name, and is not the same feature.
        return 1 !== preg_match('/^from\s/', trim(substr($value, strlen($name) + 1, -1)));
    }

    /** The name of the single CSS function a value consists of, or '' when it is anything else. */
    private static function soleFunctionName(string $value): string
    {
        $open = strpos($value, '(');
        if ( false === $open || ! str_ends_with($value, ')') ) {
            return '';
        }
        $name = substr($value, 0, $open);
        if ( 1 !== preg_match('/^[a-z][a-z0-9-]*$/', $name) ) {
            return '';
        }
        $state = CssSyntaxScanner::state();
        for ( $offset = $open, $length = strlen($value); $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ( null === $next ) {
                return '';
            }
            if ( 0 === $state['parens'] ) {
                return $length === $next ? $name : '';
            }
            $offset = $next;
        }
        return '';
    }

    /** @param callable(string): ?bool $termValue */
    private static function booleanConditionValue(string $condition, callable $termValue): ?bool
    {
        $condition = self::stripBooleanOuterParentheses(trim($condition));
        if ('' === $condition) return null;
        foreach (array( 'or', 'and' ) as $operator) {
            $terms = self::splitTopLevelBooleanOperator($condition, $operator);
            if (null === $terms) return null;
            if (1 < count($terms)) {
                $result = 'and' === $operator ? true : false;
                foreach ($terms as $term) {
                    $value = self::booleanConditionValue($term, $termValue);
                    if ('and' === $operator && false === $value) return false;
                    if ('or' === $operator && true === $value) return true;
                    if (null === $value) $result = null;
                }
                return $result;
            }
        }
        if (1 === preg_match('/^not\b\s*/i', $condition, $match)) {
            $value = self::booleanConditionValue(substr($condition, strlen($match[0])), $termValue);
            return null === $value ? null : ! $value;
        }
        return $termValue($condition);
    }

    private static function stripBooleanOuterParentheses(string $condition): string
    {
        while (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            $state = CssSyntaxScanner::state();
            $closing = null;
            for ($offset = 0, $length = strlen($condition); $offset < $length; ) {
                $next = CssSyntaxScanner::consume($condition, $offset, $state);
                if (null === $next) return $condition;
                if (0 === $state['parens']) { $closing = $next - 1; break; }
                $offset = $next;
            }
            if (strlen($condition) - 1 !== $closing) break;
            $condition = trim(substr($condition, 1, -1));
        }
        return $condition;
    }

    /** @return list<string>|null */
    private static function splitTopLevelBooleanOperator(string $condition, string $operator): ?array
    {
        $terms = array();
        $start = 0;
        $state = CssSyntaxScanner::state();
        for ($offset = 0, $length = strlen($condition); $offset < $length; ) {
            $next = CssSyntaxScanner::consume($condition, $offset, $state);
            if (null === $next) return null;
            if (CssSyntaxScanner::isTopLevel($state)
                && 0 === strcasecmp(substr($condition, $offset, strlen($operator)), $operator)
                && ! preg_match('/[a-z0-9_-]/i', $condition[$offset - 1] ?? '')
                && ! preg_match('/[a-z0-9_-]/i', $condition[$offset + strlen($operator)] ?? '')
            ) {
                $terms[] = trim(substr($condition, $start, $offset - $start));
                $start = $offset + strlen($operator);
                $offset = $start;
                continue;
            }
            $offset = $next;
        }
        if (! CssSyntaxScanner::isComplete($state)) return null;
        $terms[] = trim(substr($condition, $start));
        return $terms;
    }

    private static function mediaAlternativeApplies(string $alternative, float $viewportWidth, float $rootFontSize): bool
    {
        $alternative = strtolower(trim($alternative));
        $negated = str_starts_with($alternative, 'not ');
        if ($negated) $alternative = trim(substr($alternative, 4));
        if (str_starts_with($alternative, 'only ')) $alternative = trim(substr($alternative, 5));
        $applies = true;
        foreach (preg_split('/\s+and\s+/i', $alternative) ?: array() as $term) {
            $term = trim($term);
            $termApplies = self::mediaTermApplies($term, $viewportWidth, $rootFontSize);
            // An unknown feature has no reliable truth value in this evaluator,
            // so it cannot qualify a rule even when the query is negated.
            if (null === $termApplies) {
                return false;
            }
            if (! $termApplies) {
                $applies = false;
                break;
            }
        }
        return $negated ? ! $applies : $applies;
    }

    private static function mediaTermApplies(string $term, float $viewportWidth, float $rootFontSize): ?bool
    {
        if (in_array($term, array('', 'all', 'screen'), true)) return true;
        if (in_array($term, array('print', 'speech'), true)) return false;
        if (preg_match('/^[a-z-]+$/', $term)) return null;
        $term = trim($term, " \t\n\r\0\x0B()");
        return self::mediaWidthTermApplies($term, $viewportWidth, $rootFontSize);
    }

    private static function mediaWidthTermApplies(string $term, float $viewportWidth, float $rootFontSize): ?bool
    {
        $number = '(\d*\.?\d+)\s*(px|r?em)';
        if (preg_match('/^(min|max)-width\s*:\s*' . $number . '$/i', $term, $match)) {
            return self::compareWidth('min' === strtolower($match[1]) ? '>=' : '<=', $viewportWidth, self::widthPixels($match[2], $match[3], $rootFontSize));
        }
        if (preg_match('/^width\s*(<=|>=|<|>)\s*' . $number . '$/i', $term, $match)) {
            return self::compareWidth($match[1], $viewportWidth, self::widthPixels($match[2], $match[3], $rootFontSize));
        }
        if (preg_match('/^' . $number . '\s*(<=|<)\s*width$/i', $term, $match)) {
            return self::compareWidth('<=' === $match[3] ? '>=' : '>', $viewportWidth, self::widthPixels($match[1], $match[2], $rootFontSize));
        }
        if (preg_match('/^' . $number . '\s*(<=|<)\s*width\s*(<=|<)\s*' . $number . '$/i', $term, $match)) {
            return self::compareWidth('<=' === $match[3] ? '>=' : '>', $viewportWidth, self::widthPixels($match[1], $match[2], $rootFontSize))
                && self::compareWidth($match[4], $viewportWidth, self::widthPixels($match[5], $match[6], $rootFontSize));
        }
        return null;
    }

    private static function widthPixels(string $value, string $unit, float $rootFontSize): float { return (float) $value * ('px' === strtolower($unit) ? 1 : $rootFontSize); }
    private static function compareWidth(string $operator, float $actual, float $expected): bool { return match ($operator) { '>=' => $actual >= $expected, '>' => $actual > $expected, '<=' => $actual <= $expected, '<' => $actual < $expected }; }

    private static function compareLayers(mixed $candidate, mixed $current, bool $important): int
    {
        if ($candidate === $current) return 0;
        if (null === $candidate) return $important ? -1 : 1;
        if (null === $current) return $important ? 1 : -1;
        return $important ? $current <=> $candidate : $candidate <=> $current;
    }

    private static function compareSpecificity(mixed $left, mixed $right): int
    {
        if (is_array($left) && is_array($right)) {
            foreach (array(0, 1, 2) as $index) if (($left[$index] ?? 0) !== ($right[$index] ?? 0)) return ($left[$index] ?? 0) <=> ($right[$index] ?? 0);
            return 0;
        }
        return $left <=> $right;
    }
}

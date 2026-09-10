<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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

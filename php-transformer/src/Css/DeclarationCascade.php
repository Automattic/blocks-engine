<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared author-origin declaration precedence. */
final class DeclarationCascade
{
    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    public static function wins(array $candidate, array $current): bool
    {
        if ((bool) $candidate['important'] !== (bool) $current['important']) return (bool) $candidate['important'];
        if ((bool) ($candidate['inline'] ?? false) !== (bool) ($current['inline'] ?? false)) return (bool) ($candidate['inline'] ?? false);
        $left = $candidate['layer'] ?? null;
        $right = $current['layer'] ?? null;
        if ($left !== $right) {
            if (null === $left) return !$candidate['important'];
            if (null === $right) return (bool) $candidate['important'];
            return $candidate['important'] ? $left < $right : $left > $right;
        }
        $left = $candidate['specificity'];
        $right = $current['specificity'];
        $specificity = 0;
        if (is_array($left) && is_array($right)) {
            foreach (array(0, 1, 2) as $index) {
                $specificity = ($left[$index] ?? 0) <=> ($right[$index] ?? 0);
                if (0 !== $specificity) break;
            }
        } else {
            $specificity = $left <=> $right;
        }
        return 0 < $specificity || (0 === $specificity && $candidate['order'] >= $current['order']);
    }

    /** @param array<string,array<string,mixed>> $facts @param array<string,mixed> $candidate */
    public static function apply(array &$facts, string $property, array $candidate): bool
    {
        if (!isset($facts[$property]) || self::wins($candidate, $facts[$property])) {
            $facts[$property] = $candidate;
            return true;
        }
        return false;
    }
}

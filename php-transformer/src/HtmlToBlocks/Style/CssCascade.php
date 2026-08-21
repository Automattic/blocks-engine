<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Shared author-origin importance, specificity, and source-order cascade. */
final class CssCascade
{
    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    public static function wins(array $candidate, array $current): bool
    {
        return (int) $candidate['important'] > (int) $current['important']
            || ((bool) $candidate['important'] === $current['important']
                && ($candidate['specificity'] > $current['specificity']
                    || ($candidate['specificity'] === $current['specificity'] && $candidate['order'] >= $current['order'])));
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
}

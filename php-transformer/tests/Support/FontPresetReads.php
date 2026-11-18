<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

/**
 * Resolve a site plan's font-family preset reads to the stacks theme.json
 * defines for them, so tests can inspect the family a rule renders. A bound
 * stack the preset already holds is read through the preset alone.
 */
final class FontPresetReads
{
    /** @param array<string,mixed> $plan A WordPress site plan. */
    public static function resolve(string $css, array $plan): string
    {
        $families = array();
        foreach ($plan['writes'] ?? array() as $write) {
            if ('theme.json' !== ($write['target_path'] ?? null)) continue;
            $theme = json_decode((string) ($write['payload']['data'] ?? ''), true);
            foreach ($theme['settings']['typography']['fontFamilies'] ?? array() as $family) $families[$family['slug']] = $family['fontFamily'];
        }
        return (string) preg_replace_callback(
            '/var\(--wp--preset--font-family--([a-z0-9-]+)\)/',
            static fn (array $match): string => isset($families[$match[1]]) ? str_replace(', ', ',', $families[$match[1]]) : $match[0],
            $css
        );
    }
}

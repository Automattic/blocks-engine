<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/** Browser state fragments use live URLs; artifact documents use capture paths. */
final class CapturedStateUrls
{
    /** Rebase only HTML evidence, never selectors, identities, or the source URL. */
    public static function rebase(array $evidence, string $sourceUrl): array
    {
        foreach ($evidence as $key => $value) {
            if (is_array($value)) {
                $evidence[$key] = self::rebase($value, $sourceUrl);
            } elseif (is_string($key) && is_string($value) && str_ends_with(strtolower($key), 'html')) {
                $html = self::html($value, $sourceUrl);
                // Preserve byte-length validation: only adjust a corroborated size.
                if (($evidence[$key . 'Bytes'] ?? null) === strlen($value)) {
                    $evidence[$key . 'Bytes'] = strlen($html);
                }
                $evidence[$key] = $html;
            }
        }
        return $evidence;
    }

    private static function html(string $html, string $sourceUrl): string
    {
        $source = parse_url($sourceUrl);
        if (!is_array($source) || !isset($source['scheme'], $source['host'])) return $html;
        // Match complete tags so text and other attribute values are not URLs.
        return preg_replace_callback('/<[a-z][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>/i', static function (array $tag) use ($source): string {
            return preg_replace_callback('/(\s(?:href|action|src)\s*=\s*)(["\'])(.*?)\2|"[^"]*"|\'[^\']*\'/is', static function (array $attribute) use ($source): string {
                if (!isset($attribute[3])) return $attribute[0];
                $url = html_entity_decode($attribute[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $target = parse_url($url);
                if (!is_array($target) || !isset($target['scheme'], $target['host'])
                    || strtolower($source['scheme']) !== strtolower($target['scheme'])
                    || strtolower($source['host']) !== strtolower($target['host'])
                    || self::port($source) !== self::port($target)
                    || isset($target['user']) || isset($target['pass'])) return $attribute[0];
                // Remove only the authority, retaining authored path/query/fragment bytes.
                $path = preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', $attribute[3]) ?? $attribute[3];
                if ('' === $path || '/' !== $path[0]) $path = '/' . $path;
                return $attribute[1] . $attribute[2] . $path . $attribute[2];
            }, $tag[0]) ?? $tag[0];
        }, $html) ?? $html;
    }

    private static function port(array $url): ?int
    {
        return $url['port'] ?? match (strtolower($url['scheme'])) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}

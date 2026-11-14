<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Path;

final class ArtifactPath
{
    public static function safeRelativePath(string $path): string
    {
        $path = self::cleanInput($path);
        if ( '' === $path || 1 !== preg_match('//u', $path) || str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path) ) {
            return '';
        }

        $parts = array();
        foreach ( explode('/', $path) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    public static function resolveRelativePath(string $reference, string $sourcePath = '', bool $sanitizeSegments = false): string
    {
        $reference = self::stripQueryAndFragment($reference);
        $reference = self::cleanInput($reference);
        if ( '' === $reference || self::isAbsoluteReference($reference) ) {
            return '';
        }

        $base = '' === $sourcePath || ! str_contains($sourcePath, '/') ? '' : dirname($sourcePath) . '/';
        $parts = array();
        foreach ( explode('/', $base . $reference) as $part ) {
            $part = self::decodePathSegment($part);
            if ( str_contains($part, '/') || str_contains($part, '\\') || (bool) preg_match('/%(?:2f|5c)/i', $part) ) {
                return '';
            }
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                if ( array() === $parts ) {
                    return '';
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $sanitizeSegments ? (preg_replace('/[^A-Za-z0-9._-]/', '-', $part) ?? '') : $part;
        }

        return self::safeRelativePath(implode('/', $parts));
    }

    /**
     * The site route an href points at: its path with a trailing index file and
     * extension dropped and no trailing slash. The bare root stays `/`, and an
     * href without a path yields ''.
     */
    public static function routePath(string $href): string
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?: '');
        if ( '' === $path ) {
            return '';
        }
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/index\.[A-Za-z0-9]+$#', '/', $path) ?? $path;
        $path = preg_replace('/\.[A-Za-z0-9]+$/', '', $path) ?? $path;
        if ( '/' !== $path ) {
            $path = rtrim($path, '/');
        }

        return '' === $path ? '/' : $path;
    }

    /**
     * A link with a fragment names a section, not the page: shared chrome
     * linking to `/index.html#services` is not the served page's current item,
     * on the route holding that section or on any other.
     */
    public static function namesSection(string $href): bool
    {
        $href = trim($href);
        $hash = strpos($href, '#');
        return false !== $hash && '' !== trim(substr($href, $hash + 1));
    }

    public static function stripQueryAndFragment(string $reference): string
    {
        return strtok($reference, '?#') ?: '';
    }

    /**
     * A root-relative reference resolved against the artifact: an exact path,
     * else the path ending in it that shares the most leading directories
     * with the referring document (a capture rooted under `website/`).
     *
     * @param array<string, true> $paths
     */
    public static function artifactRootRelativePath(string $reference, string $sourcePath, array $paths): string
    {
        // Root-relative URLs need the same segment decoding and traversal checks
        // as document-relative references, before exact or suffix lookup.
        $relative = self::resolveRelativePath(ltrim($reference, '/'));
        if ( '' === $relative || isset($paths[$relative]) ) {
            return $relative;
        }

        $sourceSegments = explode('/', dirname($sourcePath));
        $matches = array();
        foreach ( array_keys($paths) as $path ) {
            if ( ! str_ends_with($path, '/' . $relative) ) {
                continue;
            }
            $candidateSegments = explode('/', dirname($path));
            $common = 0;
            while ( isset($sourceSegments[$common], $candidateSegments[$common]) && $sourceSegments[$common] === $candidateSegments[$common] ) {
                ++$common;
            }
            $matches[] = array( 'path' => $path, 'common' => $common );
        }
        usort($matches, static fn (array $left, array $right): int => $right['common'] <=> $left['common'] ?: strcmp($left['path'], $right['path']));

        return isset($matches[0]) && $matches[0]['common'] > 0 ? (string) $matches[0]['path'] : $relative;
    }

    /**
     * The artifact path a document's link (stylesheet, import) names, with
     * query and fragment removed.
     *
     * @param array<string, true> $paths
     */
    public static function resolveLinkedPath(string $href, string $sourcePath, array $paths): string
    {
        $href = (string) preg_replace('/[?#].*$/', '', $href);
        if ( ! str_starts_with($href, '/') ) {
            return self::resolveRelativePath($href, $sourcePath);
        }

        return self::artifactRootRelativePath($href, $sourcePath, $paths);
    }

    private static function cleanInput(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private static function decodePathSegment(string $segment): string
    {
        $decoded = rawurldecode($segment);
        if ( 1 === preg_match('//u', $decoded) ) {
            return $decoded;
        }

        // Retain malformed byte escapes as ASCII provenance rather than creating
        // an invalid UTF-8 path that cannot be canonically serialized.
        return preg_replace_callback('/%([0-9a-f]{2})/i', static fn (array $match): string => '%' . strtoupper($match[1]), $segment) ?? $segment;
    }

    private static function isAbsoluteReference(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $path);
    }
}

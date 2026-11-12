<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Path\RouteSlug;
use InvalidArgumentException;

/**
 * Route arithmetic for the WordPress pages a site plan publishes.
 *
 * A captured document's source path becomes a WordPress route: the entry
 * root is stripped, the extension dropped and each segment decoded. Routes
 * have a parent, ancestors and a slug; colliding routes take numbered
 * variants; pagination views belong to their owning route.
 *
 * Stateless: every method is a pure function of its inputs.
 */
final class PageRoute
{
    /** Return the owning route for a positive numbered pagination view. */
    public static function paginationParent(string $route): ?string
    {
        if (!preg_match('~/(?:page/[1-9][0-9]*|comment-page-[1-9][0-9]*)/?$~', $route, $match, PREG_OFFSET_CAPTURE)) return null;
        $parent = substr($route, 0, $match[0][1]);
        return '' === $parent ? '/' : $parent;
    }

    /** @param array<string,string> $taken */
    public static function disambiguated(string $path, array $taken): string
    {
        // The front page has no segment to number, so its duplicates take the
        // slug WordPress gives a directory index.
        $base = '/' === $path ? '/index' : $path;
        for ($suffix = 2; ; ++$suffix) if (!isset($taken[$base . '-' . $suffix])) return $base . '-' . $suffix;
    }

    public static function fromSource(string $sourcePath, string $entryRoot = ''): string { $relative = self::withoutEntryRoot($sourcePath, $entryRoot); $segments = explode('/', preg_replace('/\.[A-Za-z0-9]+$/', '', $relative) ?? $relative); $segments = array_values(array_filter(array_map(static fn(string $segment): string => RouteSlug::segment(self::decodedSegment($segment)), $segments), static fn(string $segment): bool => '' !== $segment)); if ('index' === end($segments)) array_pop($segments); return '/' . implode('/', $segments); }

    private static function decodedSegment(string $segment): string { if (!str_contains($segment, '%')) return $segment; $decoded = rawurldecode($segment); if ('.' === $decoded || '..' === $decoded || preg_match('~[/\\\\\x00]~', $decoded)) throw new InvalidArgumentException('WordPress site plan page routes reject encoded path separators and dot segments.'); return $decoded; }

    public static function withoutEntryRoot(string $sourcePath, string $entryRoot): string { if ('' === $entryRoot) return $sourcePath; $prefix = rtrim($entryRoot, '/') . '/'; return str_starts_with($sourcePath, $prefix) ? substr($sourcePath, strlen($prefix)) : $sourcePath; }

    public static function canonical(string $path): string { if (!preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$~', $path)) throw new InvalidArgumentException('WordPress site plan has an unsafe explicit page route.'); return $path; }

    public static function parent(string $path): string { $parent = dirname($path); return '.' === $parent || '/' === $parent ? '/' : '/' . trim($parent, '/'); }

    /** @return array<int,string> */
    public static function ancestors(string $path): array { $ancestors = array(); for ($parent = self::parent($path); '/' !== $parent; $parent = self::parent($parent)) $ancestors[] = $parent; return array_reverse($ancestors); }

    public static function slug(string $path): string { return trim((string) basename($path), '/'); }

    /**
     * A page keeps its derived route, or the deterministic `-2`, `-3` variant a
     * route collision gave it (see {@see RouteTable::canonical()}).
     * Validation cannot recompute which page won the collision without the
     * whole document order, so it pins the weaker but checkable property — the
     * route is the derived one or a numbered variant of it — while route
     * uniqueness is asserted across the page set.
     */
    public static function isDerived(string $path, string $expected): bool
    {
        if ($path === $expected) return true;
        $base = '/' === $expected ? '/index' : $expected;
        return 1 === preg_match('~^' . preg_quote($base, '~') . '-([0-9]+)$~', $path, $suffix) && (int) $suffix[1] >= 2;
    }
}

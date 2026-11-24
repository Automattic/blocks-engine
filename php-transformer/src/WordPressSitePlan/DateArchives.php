<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/** Native date queries retain their captured route without materializing content. */
final class DateArchives
{
    /** @return array<string,int>|null */
    private static function query(string $route): ?array
    {
        if (!preg_match('~/(\d{4})(?:/(0[1-9]|1[0-2])(?:/(0[1-9]|[12][0-9]|3[01]))?)?$~', $route, $parts) || 0 === (int) $parts[1]) return null;
        $query = array('year' => (int) $parts[1]);
        if (isset($parts[2])) $query['monthnum'] = (int) $parts[2];
        if (isset($parts[3])) {
            if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) return null;
            $query['day'] = (int) $parts[3];
        }
        return $query;
    }

    /** @return array<string,mixed>|null */
    public static function entity(string $source, string $route): ?array
    {
        $route = '/' . trim($route, '/');
        $query = self::query($route);
        return null === $query ? null : array('archive' => array('source_path' => $source, 'source_route' => $route, 'query' => $query));
    }

    /** @param mixed $entities */
    public static function assertValid(mixed $entities): void
    {
        if (!is_array($entities) || !array_is_list($entities)) throw new InvalidArgumentException('WordPress site plan date archives must be a list.');
        $sources = array();
        $routes = array();
        foreach ($entities as $entity) {
            $archive = is_array($entity) ? ($entity['archive'] ?? null) : null;
            $route = is_array($archive) ? ($archive['source_route'] ?? null) : null;
            if (!is_array($archive) || !WordPressSitePlan::safePath($archive['source_path'] ?? null) || !is_string($route) || !preg_match('~^/[^?#\s]+$~', $route) || $route !== '/' . trim($route, '/') || null === ($query = self::query($route)) || ($archive['query'] ?? null) !== $query) throw new InvalidArgumentException('WordPress site plan date archive is structurally invalid.');
            WordPressSitePlan::unique($sources, $archive['source_path'], 'date archive source');
            WordPressSitePlan::unique($routes, $route, 'date archive route');
        }
    }
}

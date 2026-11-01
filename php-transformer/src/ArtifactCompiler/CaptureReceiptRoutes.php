<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/** Resolves capture-root receipt routes against either artifact staging layout. */
final class CaptureReceiptRoutes
{
    /** @return array<string, string> Normalized source URL => artifact HTML path. */
    public static function resolve(array $files, array $receipt): array
    {
        $root = $receipt['websiteRoot'] ?? 'website';
        // Only a relative directory prefix is safe to remove.
        if (!is_string($root) || '' === trim($root) || preg_match('~^(?:[/\\\\]|[a-zA-Z]:)|(?:^|[/\\\\])\.{1,2}(?:[/\\\\]|$)~', $root)) {
            return array();
        }
        $htmlPaths = array();
        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            if (is_string($path) && preg_match('/\.html?$/i', $path) && is_string($file['content'] ?? null)) {
                $htmlPaths[$path] = true;
            }
        }
        $routes = array();
        foreach (is_array($receipt['routes'] ?? null) ? $receipt['routes'] : array() as $route) {
            if (!is_array($route) || !is_string($route['url'] ?? null) || !is_string($route['path'] ?? null)) continue;
            $path = $route['path'];
            if (!isset($htmlPaths[$path]) && str_starts_with($path, $root . '/')) {
                $path = substr($path, strlen($root) + 1);
            }
            if (isset($htmlPaths[$path])) $routes[self::normalizedUrl($route['url'])] = $path;
        }
        return $routes;
    }

    public static function normalizedUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}

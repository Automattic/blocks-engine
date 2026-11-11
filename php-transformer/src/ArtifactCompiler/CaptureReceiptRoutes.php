<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/**
 * Captured page source URLs resolved to the artifact HTML files they describe,
 * against either artifact staging layout. Every routed path names an HTML file
 * that carries text content.
 */
final class CaptureReceiptRoutes
{
    /** @param array<string, string> $paths Normalized source URL => HTML path. @param array<string, int> $indexes HTML path => file index. */
    private function __construct(private readonly array $paths, private readonly array $indexes) {}

    public static function forFiles(array $files, array $receipt): self
    {
        $root = $receipt['websiteRoot'] ?? 'website';
        $indexes = array();
        foreach ($files as $index => $file) {
            $path = $file['path'] ?? null;
            if (is_string($path) && preg_match('/\.html?$/i', $path) && is_string($file['content'] ?? null)) {
                $indexes[$path] = $index;
            }
        }
        $paths = array();
        // Only a relative directory prefix is safe to remove.
        if (!is_string($root) || '' === trim($root) || preg_match('~^(?:[/\\\\]|[a-zA-Z]:)|(?:^|[/\\\\])\.{1,2}(?:[/\\\\]|$)~', $root)) {
            return new self($paths, $indexes);
        }
        foreach (ArrayField::array($receipt, 'routes') as $route) {
            if (!is_array($route) || !is_string($route['url'] ?? null) || !is_string($route['path'] ?? null)) continue;
            $path = $route['path'];
            if (!isset($indexes[$path]) && str_starts_with($path, $root . '/')) {
                $path = substr($path, strlen($root) + 1);
            }
            if (isset($indexes[$path])) $paths[self::normalizedUrl($route['url'])] = $path;
        }
        return new self($paths, $indexes);
    }

    /** The artifact HTML path a captured page's source URL routes to, or ''. */
    public function path(string $sourceUrl): string
    {
        return $this->paths[self::normalizedUrl($sourceUrl)] ?? '';
    }

    /** @return array<int, string> Each routed artifact HTML path once, in receipt order. */
    public function paths(): array
    {
        return array_values(array_unique($this->paths));
    }

    public function pathIndex(string $path): ?int
    {
        return $this->indexes[$path] ?? null;
    }

    private static function normalizedUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
